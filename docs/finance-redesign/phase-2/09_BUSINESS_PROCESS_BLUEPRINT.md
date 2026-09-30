# 09 — Business Process Blueprint: Confirmed Target Processes (Workflows 1–7)

Documents the WNG-confirmed target processes for Workflow 1 (W1-1 through W1-9, confirmed
2026-09-23), Workflow 2 (W2-1 through W2-6, confirmed 2026-09-23), Workflow 3 (W3-1, W3-2, W3-5,
W3-6, W3-7, W3-8's operational half, confirmed 2026-09-23), and Workflow 4 (W4-1, W4-2, confirmed
2026-09-23) — see `03_WNG_FINANCE_DECISION_REGISTER.md`. **This is a process definition, not a
build.** No UI, permission, or data-model change has been made as a result of this document.
Functional roles only — no employee names are assigned (ROLE-1 in the register is what will map
these to real positions).

**Implementation status (2026-09-23):** the Workflow 1 stages below (W1-1 through W1-9) were
implemented at the backend/API/permission level in Phase 2B Implementation Wave 1 — see
`27_PHASE_2B_WAVE_1_IMPLEMENTATION_REPORT.md` for the full account. This document remains the
target-process source; it is not rewritten here. No UI was built this wave (deferred, per that
report), so the process below should be read as "true of the API today" rather than "visible to a
user today." W1-10, and the still-open items called out inline below (correction/reversal
threshold, unallocated-credit escalation age, Fully Loaded Margin), remain unimplemented.

W1-10 (credit note vs. Cost of Sales/WIP timing), W3-8's accounting treatment (whether recovery
posts to a Staff Advances GL account), and W4-2's threshold/senior approver are open
accounting/business-detail questions and are deliberately not fully represented as settled process
steps below — see the register. Likewise, W2-7 through W2-10 (PO cancellation/closure, service
confirmation, supplier credit notes, emergency purchases), W3-4's exact evidence matrix, and W4-3/
W4-4 (voucher ageing, approver delegation) are still `AWAITING WNG CONFIRMATION` and are
deliberately left out of the flows below, per the register.

---

## Principles preserved from the current architecture

These are not being changed by W1 and must not be broken by whatever implements it (see
`01_FOUNDATION_TO_PRESERVE.md`):

- **Client Receipt ≠ Revenue.** Cash arriving is booked as a liability (Client Deposits), never
  revenue, until matched to an invoice.
- **Receipt ≠ Allocation ≠ Invoice.** Three distinct events, three distinct accounting postings,
  never conflated into one transaction.
- Voiding an invoice or issuing a credit note is a genuine compensating reversal, never a silent
  edit to a posted period.
- The original invoice is never destructively deleted, including when credited or voided.

---

## Target process flow

```
Client / Enquiry
   -> Approved Quote / Commercial Basis
   -> Project
   -> Invoice Preparation
   -> Invoice Review
   -> Invoice Issue
   -> Client Payment Received
   -> Payment Verification
   -> Payment Allocation
   -> Outstanding / Credit Position
   -> Revenue
   -> Project Profitability
```

## Stage-by-stage detail

| Stage | Responsible Functional Role | Required Status Before Proceeding | Approval / Check | System Event | Accounting Event | Exception Route | Audit Evidence |
|---|---|---|---|---|---|---|---|
| Client / Enquiry | Project Officer / Client Service | Enquiry open | None | Enquiry record exists | None | — | Enquiry record, timestamps |
| Approved Quote / Commercial Basis | Project Officer (prepares); Management/Client Service (approves, per existing quote-approval workflow, unchanged by W1) | Quote approved, or a formal waiver recorded | Existing quote-approval workflow (not in scope of W1) | `resolveQuoteBasis()` picks the billing basis | None | **W1-2:** invoice may proceed without this only via a documented exception (see below) | Approved quote / waiver record |
| Project | System / Project Officer | Project created from the approved enquiry | None | Project record linked to enquiry | None | — | Project record |
| Invoice Preparation | Accounts Preparer, informed by Project Officer's billing confirmation | Approved quote/commercial basis exists, or a completed exception record | None yet — preparation is not itself an approval step | Invoice created as `draft` | None (drafting never touches the ledger — confirmed current behaviour, preserved) | **W1-2 exception path:** if no approved quote, an exception record must be completed first — reason, requesting person, authorizing approver, date/time, supporting reference — before the invoice can leave draft | Draft invoice, exception record if applicable |
| Invoice Review | Accounts Reviewer / Finance Approver — **must not be the preparer** | Invoice in `draft`, prepared | Reviewer checks the invoice against the commercial basis; may issue after checking (checker and issuer may be the same authorized person) | Invoice marked "checked by" / "checked at" | None | Reviewer sends back to Accounts Preparer for correction — **IMPLEMENTED 2026-09-23**: `returnInvoiceForCorrection()` records who/when/why, the preparer corrects the lines, and it re-enters review as "resubmitted" (closes the gap `10_W1_RECEIVABLES_GAP_ANALYSIS.md` had flagged) | Reviewer identity + timestamp on the invoice |
| Invoice Issue | Accounts Reviewer / Finance Approver (or the checker, if also authorized to issue) | Checked | Issuer authority confirmed | `issueProjectInvoice()` → status `issued` | Dr Accounts Receivable (gross), Cr Project Revenue (net), Cr Output VAT Payable (tax) — **unchanged, already correct** | Void (with mandatory reason) if issued in error and nothing has been allocated | Issued invoice, issuer identity + timestamp |
| Client Payment Received | Client (external) / whoever first records the receipt claim | — | None at capture — capture is a claim, not yet an accounting event (preserved) | `ClientReceipt` created, status `pending` | None (preserved: "a payment claim and a payment are not the same thing") | — | Receipt record |
| Payment Verification | Accounts/Finance person holding the receivables-verify authority | Receipt claim recorded | Confirms the money genuinely landed in the bank/mobile-money account | `verifyPayment()` → status `verified`; verifier + verified-at recorded distinctly | Dr Cash, Cr Client Deposits (liability) — **unchanged, already correct** | Correction/reversal of a wrongly-verified receipt is an exception requiring additional authorization (threshold not yet defined) | Verifier identity + timestamp (new, distinct field per W1-5) |
| Payment Allocation | Same or different Accounts/Finance person as verification (may be the same for routine, low-value receipts per W1-5) | Receipt verified | Matches the verified receipt to the specific invoice(s) it pays | `allocatePaymentToInvoice()`; allocator + allocated-at recorded distinctly from verifier + verified-at | Dr Client Deposits, Cr Accounts Receivable (no cash movement) — **unchanged, already correct** | Excess beyond an invoice's net balance is blocked from allocation (preserved) and becomes Unallocated Client Credit/Deposit | Allocator identity + timestamp (new, distinct field per W1-5) |
| Outstanding / Credit Position | System (computed); Finance/Accounts Lead (reviews) | — | None (a calculated view, not an approval step) | Two figures computed and separately labelled: Quote/Contract Balance and Invoice Outstanding; any excess shown as Unallocated Client Credit/Deposit with an age indicator | None | Aged unallocated credit escalates for Finance review once an age threshold is set (W1-9 — threshold still open) | The two figures + their labels, ageing indicator |
| Revenue | System (computed from posted transactions) | Invoice issued | None | — | Already recognized at Invoice Issue — this stage is a reporting view of it, not a new posting | Credit note reduces revenue immediately (preserved); the matching Cost-of-Sales/WIP treatment is W1-10, still open | Posted journal entries |
| Project Profitability | System (computed); Management (reviews) | — | None | — | Reads Revenue against Project Cost (Workflow 6, out of scope here) | — | Existing per-project margin calculation |

## Credit Note / Void sub-flow (W1-6)

| Stage | Responsible Functional Role | Required Status | Approval / Check | System Event | Accounting Event | Exception Route | Audit Evidence |
|---|---|---|---|---|---|---|---|
| Credit Note Preparation | Authorized Preparer | Invoice is `issued` or `paid` (never `draft` or already-`void`) | None yet | Credit note record created, linked via `credits_invoice_id` | None | Cannot exceed the invoice's already-allocated position (preserved) | Preparer identity + timestamp |
| Credit Note Approval / Issue | Finance Approver — may be a different person, or the same preparer only if separately authorized | Prepared | Checks and approves/issues | `issueCreditNote()` | Mirror-image reversal of the original invoice posting — **unchanged, already correct** | — | Approver identity + timestamp, reason, amount, supporting reference |
| Invoice Void | Authorized approver (mandatory reason required) | Invoice has no live allocation and no non-void credit note | Authorization + reason | `voidProjectInvoice()` | Reverses both original ledger entries via a real compensating entry — **unchanged, already correct** | Blocked entirely if any live allocation or non-void credit note exists (preserved) | Void reason, approver identity + timestamp; original invoice preserved, never deleted |

---

## Client / project financial view (target; backend implemented 2026-09-23, UI not yet built)

Per the confirmed W1-3/W1-4 direction, the eventual client/project financial summary should show:

```
Approved Quote / Contract Value
   -> Amount Invoiced
   -> Remaining to Invoice
   -> Cash Received
   -> Amount Allocated
   -> Invoice Outstanding
   -> Unallocated Client Credit / Deposit
   -> Project Revenue
   -> Project Cost
   -> Project Margin
```

| Figure | Reliable today? | Depends on |
|---|---|---|
| Approved Quote / Contract Value | Yes | — |
| Amount Invoiced | Yes | — |
| Remaining to Invoice | Yes (derivable: Contract Value − Amount Invoiced) | — |
| Cash Received | Yes, at the project level | — |
| Amount Allocated | Yes | — |
| Invoice Outstanding | Yes, separated from the project-wide figure per W1-3 — **IMPLEMENTED 2026-09-23** via `ClientFinancialPositionService` | UI surfacing only |
| Unallocated Client Credit / Deposit | Yes (derivable: Cash Received − Amount Allocated) — **IMPLEMENTED 2026-09-23**, including age in days | UI surfacing; escalation age (W1-9) still open |
| Project Revenue | Yes | — |
| Project Cost | Partially — materials actual is reliable; labour and logistics actuals are not yet attributed per project (Workflow 6/7/8, out of scope here) | Workflow 7/8 decisions |
| Project Margin | Partially, for the same reason as Project Cost | Workflow 6/7/8 decisions |

**Backend implemented 2026-09-23:** `ClientFinancialPositionService::forEnquiry()` and
`GET .../enquiries/{enquiry}/financial-position` return every figure above as one response, each
separately labelled, reusing the existing `FinanceService`/`CostAccountService` calculations
rather than inventing new ones. Project Margin is returned as direct-only, explicitly flagged
`fully_loaded_available: false` — Fully Loaded Margin itself remains unimplemented, per W6-1. No UI
implementing this view has been built yet. See `10_W1_RECEIVABLES_GAP_ANALYSIS.md` for the
per-decision classification behind this table and `27_PHASE_2B_WAVE_1_IMPLEMENTATION_REPORT.md`
for the implementation itself.

---
---

# Business Process Blueprint: Procurement to Payment (Workflow 2, Confirmed Target)

Documents the WNG-confirmed target process for Workflow 2 (W2-1 through W2-6, confirmed
2026-09-23). W2-7 through W2-10 remain open and are not represented below — see
`03_WNG_FINANCE_DECISION_REGISTER.md` and `11_W2_PROCUREMENT_DECISION_BRIEF.md`.

**Implementation status (2026-09-23):** W2-1 through W2-6 were implemented the same day (Phase 2B
Implementation Wave 2) — see `29_PHASE_2B_WAVE_2_IMPLEMENTATION_REPORT.md` for the full account.
This document remains the target-process source; the notes below flag specifically where the
"not yet built" language elsewhere in this section is now stale.

## Principles preserved from the current architecture

- **Purchase Requisition ≠ Purchase Order ≠ Goods/Service Confirmation ≠ Supplier Bill ≠
  Payment.** Five distinct documents, never merged.
- **Business Document ≠ Approval ≠ Money Movement ≠ Accounting Entry.** Confirmed sound and not
  in question here.
- The three-way match, its tamper-evident fingerprint, value-aware cover/ceiling auto-approval,
  separation of duties on approval, multiple GRNs per PO, partial bill payments, and over-payment
  prevention are all preserved unchanged (see `01_FOUNDATION_TO_PRESERVE.md` and Part 11 of the
  W2 decision brief for the full preserve list).

## Target process flow

```
Purchase Request
   -> Approval
   -> Purchase Order Preparation
   -> PO Approval
   -> Goods Receipt OR Service Confirmation
   -> Supplier Bill
   -> Bill Verification
   -> Payment Approval
   -> Payment
   -> Bank Reconciliation
   -> Accounting
```

## Stage-by-stage detail

| Stage | Responsible Functional Role | Required Status | Approval / Check | System Event | Accounting Event | Exception Route | Audit Evidence |
|---|---|---|---|---|---|---|---|
| Purchase Request | Requester (e.g. Project Officer, Site Captain, Department Head — real position TBD, ROLE-1) | Need identified | None yet | Requisition created | None | — | Requisition record |
| Approval | Requisition approver | Requisition submitted | Requisition approved, amount recorded | Requisition status → `approved` | None | — | Approver identity + timestamp |
| Purchase Order Preparation | Procurement | Approved requisition exists (or none, for an unrequisitioned order — always manual per `PurchaseApprovalPolicy`) | None yet | PO created, status `pending` | None | — | PO record, linked requisition |
| PO Approval | Named approver (creator excluded, per existing separation of duties); **senior approver above the WNG-defined high-value threshold once W2-1's threshold is set** | PO submitted (`pending_approval`) | `PurchaseApprovalPolicy`: auto-approved if covered by the requisition and within any Finance ceiling; otherwise a human approves; **W2-1, IMPLEMENTED 2026-09-23:** an additional senior-approval gate (`senior_approve` action, `PROCUREMENT_ORDERS_APPROVE_SENIOR` permission) blocks final `approve()` whenever `senior_approval_required` is set — decided at submission from `FinanceSetting::approvedValue('purchase_order_senior_approval_threshold')`. The gate exists and is tested; it stays inactive (as today) until Management/Procurement name the actual threshold and an accountant signs it off | PO status → `approved` (or `pending_approval` while awaiting a decision) | None | **W2-6, IMPLEMENTED 2026-09-23:** if incorrect while `pending_approval`, `Return for Correction` → requester corrects → `Resubmitted` → approval restarts, rather than requiring approval merely to enable a later amendment | Approver identity + timestamp; return/resubmit history now recorded |
| Goods Receipt OR Service Confirmation | Stores (goods) / Project Officer, Department Lead, or another authorized recipient (services — role TBD, W2-8, still open) | PO approved | Quantities/service accepted, checked against the order | GRN created (one or more per PO already supported); service confirmation mechanism not yet defined (W2-8, open) | Accrual for goods received not yet invoiced | — | GRN record; service confirmation record once W2-8 is decided |
| Supplier Bill | Bill creator (Accounts/Procurement) | Order approved, goods/service confirmed | Bill recorded against the PO — **W2-3, IMPLEMENTED 2026-09-23:** multiple/staged Bills may now be raised against one PO (`PurchaseOrder::remainingBillable()`), each tracked against Approved PO Value, Previously Billed Amount, Current Bill Amount, Total Billed Amount, and Remaining Billable Amount (net-of-VAT on both sides, matching the three-way match); cumulative billing is blocked beyond the valid commitment, checked against the bill's real net amount after tax pricing, not an estimate | Bill created | None yet (accrual already booked at receipt) | **W2-5, IMPLEMENTED 2026-09-23:** confirmed-duplicate supplier + invoice-number detection blocks the Bill unless an authorized, reasoned override is supplied | Bill record, its own invoice number/date/evidence; duplicate-override trail where used |
| Bill Verification | Bill verifier (not the creator) | Bill recorded | Three-way/service match — unchanged, preserved, now measured against Remaining Billable rather than the whole order total when more than one Bill exists; **W2-2, IMPLEMENTED 2026-09-23:** applicable supporting evidence (invoice, receipt, quotation, delivery note, GRN evidence, tax/eTIMS evidence, service-evidence placeholder, other) attachable via the generic `finance_attachments` mechanism — attaching evidence is enabled, not mandated; WNG has not confirmed which category is mandatory for which transaction | Bill `verified` flips false automatically if underlying facts change (fingerprint) — preserved | Accrual → Accounts Payable | — | Verification fingerprint, verifier identity + timestamp |
| Payment Approval | Payment approver (not verifier, not creator) | Bill verified | Over-payment blocked at multiple layers — preserved | — | — | — | Approver identity + timestamp |
| Payment | Payer/Reconciler | Approved | **W2-5, IMPLEMENTED 2026-09-23:** confirmed-duplicate (payment source/account + reference) detection blocks recording unless an authorized, reasoned override is supplied; cash is excluded since it legitimately carries no reference | `BillPayment` created; `Bill::updatePaymentStatus()` recomputes balance (partial payments already supported) | AP → cash/float | — | Payment record, reference number; duplicate-override trail where used |
| Bank Reconciliation | Finance/Accounts | Payment recorded | Existing reconciliation mechanism — unchanged | — | — | — | Reconciliation statement |
| Accounting | System | — | — | — | Posted via the single balanced-entry funnel — unchanged | — | Journal entries |

## Pre-approval correction sub-flow (W2-6, confirmed — IMPLEMENTED 2026-09-23)

```
Pending Approval
   -> Returned for Correction
   -> Corrected
   -> Resubmitted
```

| Stage | Responsible Functional Role | Required Status | Approval / Check | System Event | Exception Route | Audit Evidence |
|---|---|---|---|---|---|---|
| Returned for Correction | The approver reviewing the submission | `pending_approval` | Reason required | Status → `returned_for_correction` (new) | — | Return reason, returned by/at |
| Corrected | Requester/Procurement | Returned for correction | None yet | Order fields edited (only reachable in this status) | — | Changes made, recorded |
| Resubmitted | Requester/Procurement | Corrected | None yet | Status → `pending_approval` again; approval restarts | — | Resubmitted by/at, prior submission history preserved |

This is explicitly distinct from the post-approval amendment flow below — it applies only before an
order has ever been approved.

## Post-approval amendment sub-flow (W2-4, confirmed direction — IMPLEMENTED 2026-09-23)

Requester and approver are the existing `PROCUREMENT_ORDERS_CREATE` and new
`PROCUREMENT_ORDERS_AMEND` permissions respectively — no named position was invented; WNG may
still choose to restrict either grant to a narrower role than today's holders.

```
Approved PO
   -> Amendment Requested
   -> Amendment Review
   -> Reapproval where required
   -> Revised PO Version
```

| Stage | Responsible Functional Role | Required Status | Approval / Check | System Event | Exception Route | Audit Evidence |
|---|---|---|---|---|---|---|
| Amendment Requested | Requester — `PROCUREMENT_ORDERS_CREATE` (no named position invented; WNG may narrow the grant) | PO `approved` (not paid/closed is not yet a status this ERP tracks — see W2-7) | Reason, original value, revised value, fields/items changed recorded | `PurchaseOrderAmendment` record created (snapshots before/after), linked to the original PO | — | Amendment number/version, requested by/at |
| Amendment Review | Approver — `PROCUREMENT_ORDERS_AMEND` (separate from ordinary order approval; no named position invented) | Amendment requested | Materiality decided purely by which fields changed — supplier, any item, or the resulting total are commercial; delivery address/description/date/due date are administrative — never an invented KES/percentage threshold | — | — | Reviewer identity + timestamp |
| Reapproval where required | Same approver | Material (commercial) change identified | Full reapproval for commercial changes (requester cannot approve their own); an administrative change applies immediately on the lighter path with no separate reapproval | Receiving/billing against changed terms paused while a commercial amendment is pending (`hasPendingCommercialAmendment()`, checked in both GRN and Bill creation) | — | Approval status, approved/rejected by, timestamp |
| Revised PO Version | System | Amendment approved | — | The order and its items are updated to the approved snapshot; the amendment record itself — and every prior one — remains immutable and queryable as the complete history | — | Complete amendment history |

Materiality percentages/KES thresholds are explicitly not invented here — Procurement + Finance/
Management must confirm them (register).

## Cancellation / Closure (W2-7 — still open, not represented as a flow)

No target flow is defined here. WNG has not yet decided when a PO may be cancelled (before
supplier commitment; after approval but before receipt; partially received; partially billed;
fully billed; paid) or what should trigger `Closed` status. **Deletion is not cancellation** —
whatever the eventual rules, a historical approved PO must remain auditable. See
`03_WNG_FINANCE_DECISION_REGISTER.md` (W2-7) and `11_W2_PROCUREMENT_DECISION_BRIEF.md`.

---
---

# Business Process Blueprint: Expenses (Workflow 3, Confirmed Target)

Documents the WNG-confirmed target process for Workflow 3 (W3-1, W3-2, W3-5, W3-6, confirmed
2026-09-23). W3-4 (evidence standard), W3-7 (petty-cash-specific post-posting correction), and
W3-8 (salary-advance recovery visibility) remain open and are marked as such below rather than
represented as settled flow — see `03_WNG_FINANCE_DECISION_REGISTER.md` and
`13_W3_EXPENSES_DECISION_BRIEF.md`/`17_W3_EXPENSES_GAP_ANALYSIS.md`.

## Principle preserved

- Cost Collector remains the one place every expense type lands, regardless of how it was paid —
  unchanged by this workflow.
- Expense ≠ Payment ≠ Approval ≠ Accounting Entry — an approval is not evidence of a payout, a
  payout is not yet a recognised expense until substantiated (STAB-7), and neither is the same
  event as its posting.
- One economic expense must be recognised once — the STAB-7 invariant, unaffected by anything in
  this workflow.

## Path A — Normal Expense / Petty Cash Requisition

```
Expense/Requisition Request
   -> Approval
   -> Advance/Payment
   -> Expense Incurred
   -> Evidence / Surrender
   -> Finance Review
   -> Return for Correction if required
   -> Reconciliation
   -> Accounting
   -> Closed
```

| Stage | Responsible Functional Role | Required Status | Evidence | Approval / Check | Payment Event | Accounting Event | Cost Collector Event | Audit Record | Exception / Correction Route |
|---|---|---|---|---|---|---|---|---|---|
| Expense/Requisition Request | Requester | — | None yet | None yet | None | None | None | Request record | — |
| Approval | Approver (not the requester, per existing self-approval guard) | Pending | — | Budget check; expenditure-exception path if over budget (existing, preserved) | None | None | Requisition committed as a `COMMITTED` cost line (existing, preserved) | Approver identity + timestamp | — |
| Advance/Payment | Finance/Accounts | Approved | — | — | `PettyCashAdvancePoster` posts the advance (Dr Staff Advance / Cr Float) — STAB-4/STAB-7 preserved | Advance posted; commitment released (STAB-7: not yet recognised as an expense) | Commitment released, no ACTUAL cost line yet (STAB-7) | Disbursement record | STAB-4: failed GL posting flags + alerts Finance, with a controlled retry |
| Expense Incurred | Requester (external to the system) | — | — | — | None | None | None | — | — |
| Evidence / Surrender | Requester | Disbursed | **W3-4, open:** minimum evidence standard not yet decided — today, `receipt_type=none` with only a description is accepted | — | None | None | None yet | Surrender item record | — |
| Finance Review | Finance/Accounts | Surrendered, awaiting reconciliation | Evidence reviewed against W3-4 once decided; **W3-5, confirmed:** duplicate-detection check against receipt/supplier/date/amount/requester/payment reference | — | — | — | — | Reviewer identity + timestamp (once built) | **W3-6, confirmed:** `Return for Correction` if incorrect/incomplete — reason, returned by/at, original submission preserved, resubmission history retained |
| Reconciliation | Finance/Accounts | Reviewed | — | Reconciliation action itself | — | `postPettyCashSurrender()` recognises the real, itemised expense and clears the advance (STAB-7: this is now the single, correct posting owner) | Real, itemised ACTUAL cost lines created, attributed to project/category, linked to this one posting (STAB-7) | Reconciled-by/at | Idempotent — reconciling twice is refused (existing, preserved) |
| Accounting | System | Reconciled | — | — | Posted via the single balanced-entry funnel | — | — | Journal entries | **W3-7, confirmed direction, not yet built:** Original transaction → Controlled Reversal/Correction → Corrected transaction, with mandatory reason, preserved original, compensating entries, synchronized CostLine/ledger state, and a duplicate-reversal guard — today, the general reversal mechanism does not reach a `PettyCashRequisition`-sourced entry at all |
| Closed | System | Surrendered/Reconciled | — | — | — | — | — | — | — |

## Path B — Direct / Exceptional Expense (existing Direct Disbursement Request)

The existing Direct Disbursement Request path is documented as-is, not assumed to follow the same
lifecycle as a requisition advance — and it does not: it has no future surrender step at all. A
Direct Disbursement's full amount is recognised as an ACTUAL cost immediately
(`PettyCashCostProducer::postFor()`), because there is no advance to clear later — this is correct,
confirmed unaffected by STAB-7 (STAB-7's fix specifically excludes only disbursements carrying a
`requisition_id`; a Direct Disbursement has none).

| Stage | Responsible Functional Role | Required Status | Evidence | Approval / Check | Payment Event | Accounting Event | Cost Collector Event | Audit Record | Exception / Correction Route |
|---|---|---|---|---|---|---|---|---|---|
| Direct Disbursement Request | Requester | — | Free-text justification only | Approver — known dependency on **W5-2** (register): the approver sees only a written reason, with no float-availability or repeat-request signal | — | — | — | Request record | — |
| Payment | Finance/Accounts | Approved | — | — | Disbursed directly | Immediate ACTUAL cost recognised (correct — no future surrender exists for this path) | ACTUAL cost line created and posted immediately | Disbursement record | — |
| Closed | System | Paid | — | — | — | — | — | — | — |

## Path C — Salary Advance

```
Salary Advance Request
   -> Approval
   -> Payment
   -> Payroll Deduction
   -> Recovery
   -> Closed
```

Marked against the **W3-8 factual finding** (2026-09-23): the deduction-scheduling mechanics work;
almost everything around them — status, visibility, linkage, and GL treatment — does not exist yet.

| Stage | Responsible Functional Role | Required Status | Evidence | Approval / Check | Payment Event | Accounting Event | Cost Collector Event | Audit Record | Exception / Correction Route |
|---|---|---|---|---|---|---|---|---|---|
| Salary Advance Request | Employee (self-service) | — | Reason, target payroll month | 50%-of-salary safety cap (existing, preserved) | — | — | N/A — HR/Payroll, not Cost Collector | Request record | — |
| Approval | HR | Pending | — | Approver sets recovery schedule (one-off, split, or split+remainder — real, working logic) | None yet — **W3-2, confirmed gap:** no `Payment` created here today | None | — | `PayrollLedger` deduction row(s) created; `HRAuditLog` entry | — |
| Payment | HR/Finance | Approved | — | — | **W3-2, confirmed direction (not yet built):** should create a real `Payment` via the settlement architecture | **W3-2/W3-8, open:** no GL event exists for the payout today | — | Not currently tracked as a distinct status (**W3-8, open**) | — |
| Payroll Deduction | System (payroll engine) | — | — | — | Reduces net pay for the matching month(s) — genuinely applied, confirmed working (`LedgerProcessor`) | **W3-8, open:** no GL entry reduces a Staff Advances balance when this happens | — | Appears in that month's `Payslip.ledger_breakdown` only — not aggregated anywhere | — |
| Recovery | System / HR | — | — | — | — | — | — | **W3-8, open:** no outstanding-balance figure, no recovery-progress status, remainder ledger row not linked back to the request | **W3-8, open:** employment ending before full recovery is not automatically surfaced — `OffboardingFinalSettlement.deductions` is a manually-typed figure |
| Closed | — | — | — | — | — | — | — | **W3-8, open:** nothing marks an advance "fully recovered" | — |

---
---

# Business Process Blueprint: Payment Vouchers (Workflow 4, Confirmed Target)

Documents the WNG-confirmed target process for Workflow 4 (W4-1, W4-2, confirmed 2026-09-23). W4-2's
threshold/senior approver and W4-3/W4-4 (voucher ageing, approver delegation) remain open and are
marked as such rather than represented as settled — see `03_WNG_FINANCE_DECISION_REGISTER.md` and
`18_W4_PAYMENT_VOUCHER_DECISION_BRIEF.md`.

## Principles preserved

Explicitly confirmed sound and unchanged by this workflow (see `18_W4_PAYMENT_VOUCHER_DECISION_BRIEF.md`
Part 4): maker/checker/poster separation, the self-approval and self-posting guards, the
append-only voucher design, requester cancellation before posting with allocation release,
payment-source validation, exact allocation-total checks, real `Payment` creation, additive
reversal after posting, bank reconciliation, and the full audit trail. None of these is rebuilt or
weakened by adding Return for Correction/Reject.

## Target process flow

```
Verified Liability / CostLine
   -> Payment Voucher Preparation
   -> Submission
   -> Approval
        |-- Return/Reject --> Correction & Resubmit --> (back to Approval)
        |-- Approved --> Senior Approval if threshold applies (W4-2, threshold open)
   -> Post
   -> Payment
   -> Bank Reconciliation
   -> Accounting
   -> Closed
```

## Stage-by-stage detail

| Stage | Responsible Functional Role | Required Status | Approval / Check | System Event | Payment Event | Accounting Event | Exception Route | Audit Evidence |
|---|---|---|---|---|---|---|---|---|
| Verified Liability / CostLine | System (existing Cost Collector) | Liability verified | — | — | — | — | — | Cost line record |
| Payment Voucher Preparation | Requester (holds `FINANCE_SPEND_VOUCHERS_CREATE`) | Accounting period open | Allocation total must equal voucher total to the cent (existing, preserved) | Voucher created (`draft`/`pending_approval`) | — | — | — | Voucher record, allocations |
| Submission | Requester | Prepared | — | Status → `pending_approval` | — | — | — | — |
| Approval | Approver (not the requester, per existing self-approval guard) | `pending_approval`/`draft` | **W4-1, confirmed:** Approve, or Return/Reject with a mandatory reason — distinct from the requester's own `cancel()` | Status → `approved`, or → `returned_for_correction` (new), or → `rejected` | — | — | **W4-1:** `Returned for Correction` → requester corrects → `Resubmitted` → Approval again, preserving full history; this is not ordinary editing of a submitted voucher — the mechanism (editable return state vs. cancel/recreate) is a Phase 2B choice | Approver identity + timestamp; return reason, returned by/at; resubmitted by/at |
| Senior Approval if threshold applies | Named senior approver (not yet named) | Approved, above the confirmed high-value threshold (not yet set) | **W4-2, confirmed direction, threshold open:** an additional approval layered on top of, not instead of, the normal approval | — | — | — | — | Senior approver identity + timestamp, once built |
| Post | Poster (not the requester or approver, per existing guard) | Approved (and, once W4-2 is built, senior-approved where required) | Poster ≠ requester ≠ approver (existing, preserved) | Status → `posted` | Real `Payment` created (existing, preserved) | Posted via the single balanced-entry funnel | — | Payment record |
| Bank Reconciliation | Finance/Accounts | Posted | Existing reconciliation mechanism — unchanged | — | — | — | — | Reconciliation statement |
| Accounting | System | Posted | — | — | — | Journal entries, balanced | **Reversal, existing, preserved:** `PaymentReversalService::reverse()` — additive, refuses an already-voided or reconciled payment, voids (never deletes), sets a terminal `reversed` status | Journal entries |
| Closed | System | Posted/Reversed | — | — | — | — | — | — |

## Post-posting correction sub-flow (existing, preserved — not changed by W4-1/W4-2)

```
Posted Payment
   -> Controlled Reversal
   -> Original preserved + compensating accounting entries
```

This mechanism already exists and is sound for Spend Vouchers specifically, since a voucher's
payment is always a genuine `Payment` row `PaymentReversalService` can resolve directly — unlike
the petty-cash-surrender gap identified separately under W3-7.

## Still open, not represented as settled flow

- **W4-2's threshold and senior approver** — no KES amount or named role is assumed anywhere above.
- **W4-3 (voucher ageing/escalation)** — no ageing view exists today; not designed here.
- **W4-4 (approver delegation/absence)** — no substitute-authority mechanism exists today; not
  designed here.

No UI, permission, or data-model change has been made as a result of this document.

---
---

# Business Process Blueprint: Petty Cash (Workflow 5, Confirmed Target)

Documents the WNG-confirmed target process for Workflow 5 (W5-2 through W5-9, confirmed
2026-09-23; W5-1 resolved via STAB-4, not represented as a separate flow). Exact amounts —
float thresholds (W5-5), cash-count frequency and shortage/overage GL treatment (W5-7), surrender
deadline duration (W5-8), and advance-limit/exception-authority details (W5-9) — remain open and
are marked as such, not assumed. **Preserve STAB-4 and STAB-7 exactly as implemented; nothing below
reopens either.**

## Principles preserved

- **STAB-7 accounting ownership, unchanged:** Advance = Dr Staff Advance/Imprest, Cr Petty Cash
  Float (posted once, at disbursement, by `PettyCashAdvancePoster`). Surrender = Dr Project/Overhead
  Cost, Cr Staff Advance/Imprest (posted once, at reconciliation, by
  `JournalPostingService::postPettyCashSurrender()`). No path below creates a second, independent
  posting for either event — Cost Collector attribution added anywhere in this workflow must use
  `CostContext::$postsIndependently = false` wherever the economic event is already owned by one of
  these two postings, exactly as STAB-7 established.
- **STAB-4, unchanged:** a valid disbursement proceeds even if its GL posting fails; the failure is
  flagged, Finance is alerted, the audit trail is retained, a controlled retry is available, and
  retrying cannot duplicate the posting.
- **One company-wide float (W5-6, confirmed for now).**

## Normal requisition path

```
Need / Expense Request
   -> Petty Cash Requisition
   -> Approval
   -> Outstanding-Advance Check (W5-9, confirmed direction)
   -> Disbursement / Advance
   -> Expense Incurred
   -> Evidence & Surrender
   -> Finance Review
   -> Return for Correction where necessary (W3-6)
   -> Reconciliation
   -> Accounting
   -> Closed
```

| Stage | Responsible Functional Role | Required Status | Approval / Check | Evidence | Money Movement | Cost Collector Event | Accounting Event | Audit Evidence | Correction / Exception Path |
|---|---|---|---|---|---|---|---|---|---|
| Need / Expense Request | Requester | — | — | — | — | — | — | Request record | — |
| Petty Cash Requisition | Requester (full form, or the simplified variant once W5-4 is built) | — | — | Purpose stated | — | `COMMITTED` cost line created (existing, preserved) | None | Requisition record | — |
| Approval | Approver (self-approval guarded, existing) | Pending | Budget check; expenditure-exception path if over budget (existing, preserved) | — | — | — | — | Approver identity + timestamp | — |
| Outstanding-Advance Check | System, then Approver | Approved candidate | **W5-9, confirmed, not yet built:** no problematic advance → proceed; unresolved-but-not-overdue → shown to approver, not auto-blocked; overdue/unreconciled → flagged and normally blocked unless an authorized exception is recorded (reason, requester, authorizer, date/time, outstanding advance(s), new amount, evidence) | — | — | — | — | Exception record where used | Authorized exception is the only way past an overdue block |
| Disbursement / Advance | Finance/Accounts | Approved | STAB-4 preserved: failed GL posting flags + alerts, controlled retry | — | `PettyCashAdvancePoster`: Dr Staff Advance / Cr Float (STAB-7, unchanged) | Commitment released (existing, preserved); no ACTUAL cost line yet (STAB-7) | Advance posted, once | Disbursement record | STAB-4 retry endpoint |
| Expense Incurred | Requester (external) | — | — | — | — | — | — | — | — |
| Evidence & Surrender | Requester | Disbursed | **W3-4, open:** evidence standard depends on expense type/amount/circumstances, still to be matrixed | Per W3-4 once decided | — | — | — | Surrender item record | — |
| Finance Review | Finance/Accounts | Surrendered, awaiting reconciliation | **W3-5, confirmed:** duplicate-detection check | — | — | — | — | Reviewer identity + timestamp | **W3-6, confirmed:** Return for Correction, reason required, original preserved, resubmission history retained |
| Reconciliation | Finance/Accounts | Reviewed | — | — | — | Real, itemised `ACTUAL` cost lines created, attributed, linked to the one surrender posting (STAB-7) | `postPettyCashSurrender()`: Dr Project/Overhead Cost, Cr Staff Advance (STAB-7, the single posting owner) | Reconciled-by/at | Idempotent — reconciling twice refused (existing, preserved) |
| Accounting | System | Reconciled | — | — | — | — | Posted via the single balanced-entry funnel | Journal entries | **W3-7, confirmed direction, not yet built:** Original → Controlled Reversal/Correction → Corrected, with mandatory reason, preserved original, compensating entries, synchronized CostLine/ledger state, duplicate-reversal guard |
| Closed | System | Surrendered/Reconciled | — | — | — | — | — | — | — |

## Direct / exceptional path (existing Direct Disbursement Request, preserved)

```
Exceptional Need
   -> Direct Disbursement Request
   -> Approval Review with enhanced signals (W5-2, confirmed)
   -> Approve OR Reject with Reason (existing, preserved)
   -> Payment
   -> Cost Collector
   -> Accounting
   -> Closed
```

| Stage | Responsible Functional Role | Required Status | Approval / Check | Evidence | Money Movement | Cost Collector Event | Accounting Event | Audit Evidence | Correction / Exception Path |
|---|---|---|---|---|---|---|---|---|---|
| Exceptional Need | Requester | — | — | Free-text justification (existing) | — | — | — | — | — |
| Direct Disbursement Request | Requester | — | — | — | — | — | — | Request record, `payload` | — |
| Approval Review with enhanced signals | Approver (self-approval guarded, existing) | `pending_approval` | **W5-2, confirmed, not yet built:** amount, requester, project/overhead classification, justification, current float availability, requester's recent Direct Disbursement activity, unresolved/outstanding advances, evidence where applicable, existing budget/exception information — no threshold invented | Per whatever is available | — | — | — | — | — |
| Approve OR Reject with Reason | Approver | Reviewed | Existing, preserved — `rejectDirectRequest()` already requires a reason | — | — | — | — | Approver identity + timestamp; rejection reason if rejected | — |
| Payment | Finance/Accounts | Approved | — | — | Disbursed directly (no future surrender — correct, unaffected by STAB-7 since there is no `requisition_id`) | Immediate `ACTUAL` cost line, posted once | Posted immediately | Disbursement record | — |
| Cost Collector | System | — | — | — | — | Attribution recorded | — | — | — |
| Accounting | System | — | — | — | — | — | Posted via the single balanced-entry funnel | Journal entries | Existing general reversal mechanism (sound for `Payment`-sourced entries) |
| Closed | System | Paid | — | — | — | — | — | — | — |

## Float management

```
Top-Up
   -> Float Balance
   -> Physical Custody
   -> Cash Count / Reconciliation (W5-7, confirmed, not yet built)
   -> Variance Review if applicable
```

| Stage | Responsible Functional Role | Required Status | Approval / Check | Evidence | Money Movement | Accounting Event | Audit Evidence | Correction / Exception Path |
|---|---|---|---|---|---|---|---|---|
| Top-Up | Finance/Accounts | — | Existing, preserved | — | Float increased (existing, preserved) | Existing top-up posting path | Top-up record | Existing: `destroy()` refuses a top-up with a linked disbursement and posts a compensating reversal before removing the row |
| Float Balance | System | — | — | — | — | Recalculable from ledger entries (existing) | `PettyCashBalance` row | — |
| Physical Custody | Custodian (`held_by`, new — W5-3) | — | — | — | — | — | Custodian identity, distinct from `created_by` | Handover record (below) if custodian changes |
| Cash Count / Reconciliation | Counter + Custodian + Verifier/Reviewer where applicable | — | **W5-7, confirmed, not yet built:** count date/time, physical cash counted, system float balance, `Physical Cash Count − ERP Float Balance = Variance`, shortage/overage, counter, custodian, verifier, variance explanation, corrective/action reference, evidence | Per W5-7 | None — a count never silently changes the ledger | **Not decided:** shortage/overage GL treatment is `AWAITING FINANCE/ACCOUNTANT CONFIRMATION` | Count record | Any correction goes through the controlled, auditable path W5-7 requires, once designed — never a silent ledger edit |
| Variance Review if applicable | Finance/Accounts (and Management where the variance warrants it) | Count recorded | Not decided — see W5-7 | — | — | Not decided — see W5-7 | Review outcome | — |

## Custodian handover (W5-3, confirmed, not yet built)

```
Current Custodian
   -> Physical Count
   -> Handover Record
   -> New Custodian
```

| Stage | Responsible Functional Role | Required Status | Approval / Check | Evidence | Money Movement | Accounting Event | Audit Evidence | Correction / Exception Path |
|---|---|---|---|---|---|---|---|---|
| Current Custodian | Outgoing custodian | — | — | — | None | None | Outgoing custodian identity | — |
| Physical Count | Outgoing + incoming custodian, verifier where the architecture supports it | — | System float balance vs. physical cash counted at handover; variance if any | Count at handover | None | None | Handover count figures | — |
| Handover Record | System | Count recorded | Confirmation by the parties/authorized checker where supported | — | None | None | Outgoing custodian, incoming custodian, handover date/time, system float balance, physical cash counted, variance | Historical transactions are never rewritten by a handover |
| New Custodian | Incoming custodian | Handover recorded | — | — | — | — | Incoming custodian identity | — |

No UI, permission, or data-model change has been made as a result of this document.

---
---

# Business Process Blueprint: Project Costing & Profitability (Workflow 6, Confirmed Target)

Documents the WNG-confirmed target process for Workflow 6 (W6-1 through W6-10, confirmed
2026-09-23; W6-11 preserved as an existing control; W6-12 confirmed not supported as a feature).
W6-1A (overhead allocation methodology), W6-5/W6-6's exact blocking rules and reopen authority, and
W6-7 (write-off policy) remain open and are marked as such. **No new STAB-7-class defect was found
in this workflow — STAB-4, STAB-7, Cost Collector, and the existing per-project margin calculation
are unchanged.**

## Principles preserved

- Direct Project Margin remains the current, reliable, operational measure — computed from real
  posted revenue and real `ACTUAL` cost lines, exactly as today.
- Fully Loaded Margin is a confirmed future target, not a currently reliable figure — never shown
  without being clearly labelled as such, and never collapsed into one generic "Margin."
- Commitment (`NATURE_COMMITTED`) and Planned (`NATURE_PLANNED`) cost lines remain correctly
  excluded from margin — only `NATURE_ACTUAL` (or a genuine WIP release, once STAB-2 is resolved)
  counts.
- The existing budget append-only history (`cost_lines` superseded-and-replaced pattern) and
  `BudgetRevisionRecorder` are preserved unchanged (W6-11).

## Target process flow

```
Project / Approved Commercial Basis
   -> Project Budget
   -> Planned Cost
   -> Committed Cost
   -> Accrued Cost
   -> Actual Direct Project Cost
   -> Shared-Cost Allocation where applicable (W6-3)
   -> Cost Correction / Project Transfer where applicable (W6-4)
   -> Project Revenue
   -> Direct Project Margin
   -> Fully Loaded Margin (only once W6-1A/W7/W8/W9 dependencies are resolved)
   -> Profitability Review
   -> Project Financial Closure (W6-5)
   -> Final Project Profitability (W6-10)
```

## Stage-by-stage detail

| Stage | Responsible Functional Role | Required Status | Approval / Check | Source Data | CostLine Event | Accounting Event | Audit Evidence | Exception / Correction Route |
|---|---|---|---|---|---|---|---|---|
| Project / Approved Commercial Basis | Project Officer / Management (existing, W1) | Quote approved | Existing quote-approval workflow | Approved quote | — | — | Quote/waiver record | — |
| Project Budget | Project Officer, confirmed by Finance where applicable | Project created | None today — budget approval was deliberately retired 2026-07-07 (W6-11, preserved) | `task_budget_data` JSON | `NATURE_PLANNED` lines projected by `BudgetProjector` | None | `BudgetRevisionRecorder` event, only once money is committed (W6-11) | Budget remains freely editable pre-commitment; append-only history once committed |
| Planned Cost | System | Budget projected | — | — | `NATURE_PLANNED` | None | — | Superseded-and-replaced on revision, never edited in place |
| Committed Cost | System, from approved requisitions/POs | Requisition/PO approved | Existing approval controls (per module) | Approved requisition/PO | `NATURE_COMMITTED` | None | — | Released via existing mechanisms (`releaseCommitment()`/`releaseFor()`) when superseded or fulfilled |
| Accrued Cost | System, from GRN acceptance | Goods/service received, not yet invoiced | Three-way match in progress | GRN | `NATURE_ACCRUED` | Accrual posted | — | Withdrawn automatically if underlying facts change (existing) |
| Actual Direct Project Cost | System, from whichever module reports it (Materials, Bills, Petty Cash, Direct Disbursements, Direct Bills/Subcontractors) | Verified | Per-module controls, all previously audited/fixed (including STAB-7) | Whichever source posted the cost | `NATURE_ACTUAL` | Posted via the single balanced-entry funnel | Journal entries | Per-module correction path (e.g. STAB-7's for petty cash) |
| Shared-Cost Allocation where applicable | Finance/Accounts (allocator), reviewer where required | Source cost verified | **W6-3, confirmed, not yet built:** sum of allocated shares must equal the original source amount exactly; no new Payment/Journal Entry created merely to split the analysis | Original source transaction | Allocation record referencing the original CostLine (mechanism TBD — Phase 2B) | None new — no re-posting | Allocation basis/reason, allocated by, date/time, reviewer where required | — |
| Cost Correction / Project Transfer where applicable | Requester + Finance/Accounts approver | Cost already `ACTUAL` | **W6-4, confirmed, not yet built:** reclassification, never duplication or silent edit | Original CostLine | Original CostLine reduced/reversed on Project A; correcting CostLine added on Project B | Accounting-dimension correction where required | Original project, corrected project, amount, reason, requested/approved by, date/time | — |
| Project Revenue | System (existing, W1) | Invoice issued | Existing controls | Issued invoice | — | Existing, unchanged | Journal entries | Existing, W1 |
| Direct Project Margin | System | — | None (a calculated view) | Billed revenue + `NATURE_ACTUAL` cost lines | — | — | — | — |
| Fully Loaded Margin | System | W6-1A + W7 + W8 (+ possibly W9) resolved | Not yet buildable | Direct cost + allocated overhead | — | — | — | Must be clearly labelled as dependent/not-yet-reliable until every dependency resolves |
| Profitability Review | Management (W6-8) | — | — | Direct (and, later, Fully Loaded) margin | — | — | — | Margin warning/escalation thresholds already exist (`FinanceSetting`), values not verified here |
| Project Financial Closure | Finance/Accounts, Management sign-off | Operationally complete | **W6-5, confirmed, not yet built:** checklist of open items (uninvoiced amounts, open commitments, unverified bills, outstanding advances, unresolved transfers/credit notes/exceptions) — hard-blocker vs. warning split not yet decided | Every open-item source listed in W6-5 | — | — | Closure record, checklist result | **W6-6, confirmed, not yet built:** late-cost/reopen exception, never a silent reopen |
| Final Project Profitability | System | Financially closed | — | Same margin calculation, frozen/labelled as final | — | — | — | W6-6 reopens the calculation, transparently, if a late cost is authorized |

## Still open, not represented as settled

- **W6-1A** — overhead allocation methodology (pool, driver, period, treatment of edge cases).
- **W6-5/W6-6** — the exact hard-blocker-vs-warning checklist for closure, and who authorizes a
  late-cost reopen.
- **W6-7** — whether the existing commitment-release mechanisms fully solve "commitment cleanup,"
  and the separate, still-undecided true accounting write-off policy.
- **W6-11's optional baseline-comparison report** — not decided, not built.
- **W6-12** — historical (as-of-date) profitability — confirmed not built; a reporting enhancement
  candidate, not a data-model gap.

No UI, permission, or data-model change has been made as a result of this document.

---
---

# Business Process Blueprint: Labour Cost Attribution (Workflow 7, Confirmed Target)

Documents the WNG-confirmed target process for Workflow 7 (W7-1A/B/C through W7-14, confirmed
2026-09-23). W7-2's exact role scope, W7-3's rate methodology, and W7-12's rate composition remain
open and are marked as such. **Nothing here is implemented — no crew table, Job Card change,
payroll-posting change, or labour CostLine exists yet.**

## Non-negotiable principle

**Payroll remains the authoritative company-level accounting event. A labour CostLine is an
analytical project-cost allocation of that same expense, never a second, independently posted
company expense.** Reuse the STAB-7 pattern exactly: the original payroll posting is the GL
accounting event; the labour CostLine (once built) uses `CostContext::$postsIndependently = false`
or an architecture-equivalent mechanism, so project attribution never manufactures new company
expense. Payroll posting itself is not modified by this workflow.

## Casual / Technical Labour path

```
Worker / Job Card
   -> Time Capture
   -> Project Attribution (new — not yet built)
   -> Operational Approval
   -> Labour Cost Calculation
   -> CostLine
   -> Project Cost
   -> Reconciliation to Labour Cost Pool
```

| Stage | Responsible Functional Role | Source Record | Approval | Project | Time/Unit Basis | Rate Source | CostLine Event | GL Event | Privacy Boundary | Audit Evidence |
|---|---|---|---|---|---|---|---|---|---|---|
| Worker / Job Card | Casual/technical worker | `JobCard` (existing) | — | Not yet captured | Clock-in/out (existing) | — | — | — | — | Existing Job Card record |
| Time Capture | Worker/site supervisor | `JobCard.total_hours`/`overtime_hours` (existing) | — | — | Hours (existing) | — | — | — | — | Existing |
| Project Attribution | Site supervisor (new field on the existing record, not a new system) | `JobCard` extended | — | New field, not yet built | — | — | — | — | — | New field |
| Operational Approval | Existing Job Card approver, extended | `JobCard.status` (existing pattern reused) | Existing approval, extended to cover project attribution | — | — | — | — | — | — | Approver identity + timestamp (existing pattern) |
| Labour Cost Calculation | System | — | — | — | Hours × rate | `TechnicalLabour.day_rate` (existing, unused today) | — | — | Cost figure only, no salary detail (W7-3) | — |
| CostLine | System | — | — | Confirmed at Time Capture | — | — | `NATURE_ACTUAL`, `postsIndependently: false` | Payroll's own posting is unaffected | — | CostLine record |
| Project Cost | System | — | — | — | — | — | — | — | — | Included in project cost account |
| Reconciliation to Labour Cost Pool | Finance/Accounts | — | — | — | — | — | — | — | — | W7-14's invariant checked |

## Permanent Production / Site Crew path

```
Crew / Team Assignment
   -> Project / Work Date
   -> Work Phase / Split where applicable
   -> Operational Confirmation
   -> Labour Cost Calculation
   -> CostLine
   -> Project Cost
   -> Reconciliation
```

| Stage | Responsible Functional Role | Source Record | Approval | Project | Time/Unit Basis | Rate Source | CostLine Event | GL Event | Privacy Boundary | Audit Evidence |
|---|---|---|---|---|---|---|---|---|---|---|
| Crew / Team Assignment | Production/Site Lead | New — no crew/team model exists today | — | — | — | — | — | — | — | New record, not yet built |
| Project / Work Date | Production/Site Lead | — | — | Confirmed here | — | — | — | — | — | — |
| Work Phase / Split where applicable | Production/Site Lead | — | — | May split across projects (W7-6) | — | — | — | — | — | — |
| Operational Confirmation | Authorized Production/Site/Project supervisory role (W7-13) | — | New approval, not yet built | — | — | — | — | — | — | Confirmer identity + timestamp |
| Labour Cost Calculation | System | — | — | — | Crew-day × approved rate | Standard/approved rate (W7-3, methodology open) | — | — | Cost figure only | — |
| CostLine | System | — | — | — | — | — | `NATURE_ACTUAL`, `postsIndependently: false` | Payroll's own posting is unaffected | — | CostLine record |
| Project Cost | System | — | — | — | — | — | — | — | — | Included in project cost account |
| Reconciliation | Finance/Accounts | — | — | — | — | — | — | — | — | W7-14's invariant checked |

## Non-Project Labour path

```
Attendance / Payroll
   -> Non-Project Classification
   -> Overhead
```

No project CostLine is created unless the labour is directly attributable per W7-1B/W7-2/W7-4
through W7-11's confirmed carve-outs. This is the default path for office/admin labour (W7-1C),
idle time (W7-7), general training (W7-10), and management time (W7-11) unless a specific,
confirmed exception applies.

## Rework path

```
Project Labour
   -> Normal Work
   OR
   -> Client-Caused Rework (W7-8)
   OR
   -> Internal Rework / Quality Loss (W7-9)
```

All three remain traceable to the project without duplicate payroll posting — each is the same
CostLine mechanism above, differing only in an analytical classification tag. Internal-error
rework is never silently reclassified as overhead merely to improve a project's apparent margin.

## Still open, not represented as settled

- **W7-2** — which office/project-support roles must actually allocate time.
- **W7-3 / W7-12** — the final labour cost-rate methodology and its component composition
  (statutory costs, benefits, allowances, overtime premiums).
- **W7-13's exact role mapping** — deferred to ROLE-1/ROLE-2.

No UI, permission, or data-model change has been made as a result of this document.
