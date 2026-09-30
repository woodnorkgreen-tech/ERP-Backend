# 05 — Current Workflows: AR, AP, Expenses, Payments/Receipts, Project Finance, Evidence

Part of the WNG ERP Finance & Accounts Phase 1 audit, dated 2026-09-22. Covers audit sections 5
(Accounts Receivable), 6 (Accounts Payable), 7 (Expense Management), 8 (Payments and Receipts),
11 (Project Finance), and 21 (Attachments/Supporting Documents). All findings verified fresh
against current code, not the stale 2026-07-21 `finance-module-audit.md`.

---

## PART A — Accounts Receivable

### A.1 The trace, as it actually exists

```
ProjectEnquiry (quote approved / waived)
   -> resolveQuoteBasis() picks the billing basis: approved snapshot > legacy
      client_approved_quote > approved TaskQuoteData > formal waiver > none
   -> ClientReceipt (cash arrives; status pending)
   -> verifyPayment() -> EnquiryPayment.status='verified'  [ACCOUNTING EVENT #1]
   -> ProjectInvoice created as 'draft' (createProjectInvoice)
   -> issueProjectInvoice() -> status='issued'              [ACCOUNTING EVENT #2]
   -> allocatePaymentToInvoice() matches a verified EnquiryPayment
      to an invoice line                                    [ACCOUNTING EVENT #3]
   -> invoice.status -> 'paid' once net total is fully allocated
   -> optional: createCreditNote() / issueCreditNote()       [ACCOUNTING EVENT #4]
   -> optional: voidProjectInvoice() (only if unallocated, no live credit note)
```

Files: `app/Modules/Projects/Http/Controllers/EnquiryController.php` (L1049-2001),
`app/Modules/Projects/Services/FinanceService.php`, `app/Modules/Finance/Models/{ProjectInvoice,
ProjectInvoiceLine,ClientReceipt}.php`, `app/Modules/Finance/Services/{InvoicePricer,
ReceivablesPostingService,WorkInProgressReleaseService}.php`.

### A.2 Where revenue actually posts to the ledger

**FACT.** Three, and only three, functions ever create a `JournalEntry` on the AR side, each
gated on a status transition, never on a document being "sent," "printed," or "viewed":
- `FinanceService::verifyPayment()` → `ReceivablesPostingService::postClientReceipt()` — Dr cash,
  Cr **Client Deposits (2200)** liability. Triggered on verification, never on capture.
- `EnquiryController::issueProjectInvoice()` → `postInvoiceIssued()` — Dr **Accounts Receivable
  (1100)** gross, Cr **Project Revenue (4100)** net, Cr **Output VAT Payable (2110)** tax.
- `EnquiryController::allocatePaymentToInvoice()` → `postInvoiceAllocation()` — Dr Client Deposits,
  Cr Accounts Receivable, no cash movement.
- `EnquiryController::issueCreditNote()` → `postCreditNoteIssued()` — mirror-image reversal.

**FACT.** `logPayment()` (recording a receipt claim) and `createProjectInvoice()` (drafting an
invoice) deliberately do **not** touch the ledger — code comments state this explicitly ("a payment
claim and a payment are not the same thing"). **This directly answers the audit's operational-vs-
accounting-event question: the codebase already treats them distinctly, and documents why. No
conflation found on the AR side.**

### A.3 Numbering, dates, terms, tax, lines

- Invoice number: `INV-{Ym}-{6-digit id}`. Credit note: `CN-{Ym}-{6-digit id}`, same series family,
  distinguished only by `credits_invoice_id` being non-null.
- `invoice_date`/`due_date` required, `due_date >= invoice_date` validated. **No configurable
  "payment terms" concept** (e.g. Net 30) exists — due date is a free-form pick per invoice.
- Tax: `InvoicePricer::priceLine()` resolves VAT by `vat_treatment_id` **effective on the invoice
  date**, rounds once per line, sums for the header (`retotal()`) — materially more rigorous than
  the pre-2026-09-08 state.
- **ISSUE — no discount field anywhere in the AR pipeline.** A negative line is explicitly rejected
  ("Raise a credit note to reduce an invoice"). A discounted invoice must be typed as an
  already-discounted `unit_price`, with no separate audit trail of the discount granted.
  RISK: LOW.
- Partial payments/overpayment: `allocatePaymentToInvoice()` hard-blocks any allocation exceeding
  the invoice's net balance. Excess receipt money stays **unallocated indefinitely** — there is no
  automatic refund or credit-note-for-overpayment workflow.
- Cancellations/reversals: `voidProjectInvoice()` reverses **both** ledger entries it created via
  `reverseEntry()` — a genuine compensating entry, never an edit to a posted period. Refuses to void
  an invoice with any live allocation or non-void credit note.
- Invoice status: `draft`, `issued`, `paid`, `void` — **no `partially_paid` status**; this was
  deliberately removed because nothing could ever set it (documented in code, not an oversight).

### A.4 Credit-note workflow — REFUTED: a full workflow exists

**FACT.** `createCreditNote()`/`issueCreditNote()` is a full, live workflow: a credit note is a
`project_invoices` row with `credits_invoice_id` set and **negative** money columns (chosen so
every existing `SUM(total_amount)` nets correctly with zero code changes elsewhere). Can only be
raised against an issued/paid invoice, never a draft or already-void one; cannot itself be credited;
capped so it cannot take the invoice's net position below what's already allocated.

**ISSUE (MEDIUM), named/documented limitation:** issuing a credit note does **not** reverse any
Work-in-Progress/Cost-of-Sales share the credited invoice's revenue already released —
`WorkInProgressReleaseService` was never built to release a *negative* share. Company-level P&L
self-corrects when credited revenue reverses, but the per-job Cost-of-Sales split stays slightly
ahead of the credit until this is built.

### A.5 KNOWN GAP #1 — "Fully paid" status: CONFIRMED, two disagreeing definitions

**ISSUE — RISK: HIGH.**
1. **Per-invoice (correct):** `ProjectInvoice::scopeWithVerifiedPaidAmount()` sums only verified,
   non-reversed allocations for that specific invoice — genuinely allocation-based.
2. **Project-level (still receipt-vs-quote):** `FinanceService::getPaymentProgress()` computes
   `remaining = total_quote - total_paid`, where `total_paid` is the sum of verified `EnquiryPayment`
   amounts against the **whole project**, with **no reference to `project_invoice_allocations` at
   all**.

**Consequence:** a project can show "Fully paid"/land in the Settled tab purely because verified
receipts reached the quote total, even if a receipt was never matched to any specific invoice —
aged, uninvoiced revenue can hide behind a green "Fully paid" badge.

### A.6 KNOWN GAP #2 — "Needs action" tab drops a pending receipt once threshold is met: CONFIRMED

**ISSUE — RISK: MEDIUM.** Both backend (`receivablesSummary()`) and frontend
(`ProjectReceivablesIndex.vue`) use the identical predicate, deliberately kept in lockstep, and
neither references `pending_payment_count`. Once verified receipts already clear the mobilization
threshold, any additional receipt sitting unverified is excluded from the "Needs action" tab.
Partial mitigation: a small amber "N pending verification" badge shows inline wherever the project
does land — not silent, but invisible to anyone working the desk tab-by-tab.

---

## PART B — Accounts Payable

### B.1 The trace, as it actually exists

```
Requisition (approved)
   -> PurchaseOrder (status: pending -> approved via approve())
   -> GoodsReceiptNote / GoodsReceiptNoteItem (Stores accepts quantities)
   -> Bill  (PO-backed: requires purchase_order_id, inherits supplier;
             OR Direct Bill: purchase_order_id null, requires supplier_id +
             expense_code_id — no PO/GRN/three-way match)
   -> BillController::verify()  [ACCOUNTING EVENT: accrual -> Accounts Payable]
   -> BillController::recordPayment() / recordMultiBillPayment()
        -> BillPayment::creating -> SupplierPaymentGuard::assertPayable()
        -> BillPayment::created  -> JournalPostingService::postSupplierPayment()
                                    [ACCOUNTING EVENT: AP -> cash/float]
   -> Bill::updatePaymentStatus() recomputes balance/status after every payment
```

### B.2 Three-way match — well-built

**FACT.** `PurchaseOrderWorkflow::bill()` implements a real 6-point three-way match (order
approved, supplier match, invoice number recorded, goods accepted by Stores, invoice ≤ value
accepted into stock, invoice ≤ approved order total). Verification stamps a **sha256 fingerprint**
of everything checked; if the order, receipt, or invoice figures change after sign-off, the stored
fingerprint stops matching and `verified` flips back to false on the next read — genuinely
tamper-evident, not just tamper-logged. `Bill::withdrawVerificationOnChange()` is a second line of
defense.

Direct bills get an analogous but narrower check (supplier registered, classification set, invoice
number recorded — no quantity/receipt matching, since there is nothing to match). PO-backed and
direct bills are correctly modelled as **the same `Bill` row shape** with a nullable
`purchase_order_id`, not two parallel tables.

### B.3 Duplicate supplier invoice prevention — partial

**FACT.** One cross-module guard exists (`BillController::store()` checks whether a `CostLine`
already recorded the same supplier+invoice-number pair under the `unpaid_invoice` funding mode).

**ISSUE — RISK: HIGH.** There is **no** check anywhere (or DB unique constraint) preventing the
**same supplier + same `supplier_invoice_number` being entered twice as two separate `Bill` rows**
— the most common real-world duplicate-invoice scenario. Two independent entries for the same
physical invoice would both pass validation and, once both verified, double the recognized
liability and the AP/Accrued-Expense debits.

### B.4 GRN-accrual double payment — REFUTED, already fixed

**FACT.** A `settled_by_bill_id` guard is enforced at multiple independent layers:
`markGrnAccrualsSettledByBill()` stamps every GRN-accrual cost line the moment a bill posts;
`resolveVerifiedLiabilityAccount()` — shared by every liability-settling path — explicitly throws
if a cost line is already settled by a bill, citing the exact historical incident this prevents;
`CostLine::scopeUnsettled()`/`scopeSettled()` exclude/include consistently. **No further action
needed** beyond ensuring any future liability-settling path also routes through
`resolveVerifiedLiabilityAccount()`.

### B.5 Direct Bill vs PO-backed Bill — cleanly modelled

**FACT.** A Direct Bill requires its own supplier and a **procurable, non-"Direct materials"**
expense code — deliberately blocking a real materials purchase from bypassing the
budget-commitment/three-way-match discipline. Posts its accrual-clearing debit to its own expense
account rather than Accrued Expenses, since it never had a GRN accrual. PO-backed and Direct bills
share one table, one payment path, one guard, one `BillPayment` model — genuinely one document type
with two verification bases, not two parallel systems.

### B.6 Payment controls — strong, with one real gap

**FACT — over-payment blocked in depth.** Single-bill and batch payments are validated against the
bill's/bills' balance at three independent layers (controller, `SupplierPaymentGuard`,
`BillPayment::creating`) — every entry point is forced through the same final gate. A batch is
refused *whole* rather than partially if any one invoice in it is blocked.

**FACT — payment without approved documentation blocked**, and the "Supplier Credit" wash-entry
defect (fixed 2026-09-14) remains closed on both single and batch payment paths.

**ISSUE — RISK: MEDIUM-HIGH.** `bill_payments.reference_number` carries **no uniqueness
constraint** (DB or application), while the client side (`EnquiryController::updatePayment()`)
enforces one. The same bank transaction reference could be recorded twice as two separate
`BillPayment` rows, double-counting a single real bank transfer with no system-level catch.

### B.7 Unauthorized changes after approval — CRITICAL, not prevented

**ISSUE — CRITICAL.** `PurchaseOrderController::update()` has **no status check whatsoever** —
it accepts a full item replace-all against **any** PO regardless of status, including one already
approved, delivered against, invoiced, or paid. No permission beyond the outer route-group
middleware, no audit log entry, no re-approval trigger.

**ISSUE — CRITICAL, compounding.** `PurchaseOrderController::destroy()`'s pending-only guard is
**commented out in the source**. Because `purchase_order_items`, `goods_receipt_notes`, and `bills`
all cascade-delete on `purchase_order_id`, deleting an approved, delivered, invoiced, and
**already-paid** PO cascade-deletes its Bill(s) and GRN(s) at the database level — bypassing
`Bill::destroy()`'s own protection entirely (a cascade delete never calls it), and orphaning already
-posted `JournalEntry` rows and `BillPayment` history that point at a bill and PO that no longer
exist. **This is a real path to silently destroying part of the audited GL trail for a transaction
that has already moved cash.** Mitigating factor: because the verification fingerprint includes
order total and item quantities/prices, a post-approval edit to an already-invoiced order will
silently invalidate that bill's verification on its next read — but the edit itself is unaudited
and undated relative to approval.

### B.8 PO / Bill / Payment as distinct documents — confirmed correct

**FACT.** Three separate tables, three status vocabularies, three numbering series, three
controllers — never conflated into one record. `PurchaseOrderWorkflow` composes a read-only
combined view for the UI, but the underlying documents remain distinct and independently
auditable. **No, they are not incorrectly treated as one transaction anywhere found.**

---

## PART C — Expense Management

### C.1 Petty Cash Requisition (project/site/departmental expense via the tin)

Full lifecycle in one model: request → approval (with project-budget governance gate + documented
"expenditure exception" override, permission-gated and audit-logged) → disbursement (mandatory
`payment_source_id`+`payment_method`, posts an advance journal Dr 1300/Cr Cash) → documentation
(itemised surrender with receipt type/number/KRA PIN/supplier) → reconciliation (creates verified
ACTUAL cost lines, releases the COMMITTED line, posts a clearing journal).

**FACT — the prior-audit "reconcileSurrender has no server-side status guard" concern is NOT
true.** `reconcileSurrender()` explicitly re-checks the requisition status and is covered by a
dedicated regression test proving a second reconcile attempt is refused and does not double-post.
**Resolved, not an open gap.**

**ISSUE — RISK: HIGH, possible double-posting of the surrender's own journal.**
`CostCollectorService::postFromSource()` unconditionally posts a journal per newly-created ACTUAL
cost line — including the per-item lines `reconcileSurrender()` creates. Separately, and
unconditionally, `reconcileSurrender()` **also** calls `postPettyCashSurrender()`, which builds its
own balanced journal from the same per-item amounts. No code was found that suppresses the
automatic per-line posting for these lines, and the existing regression test only asserts the
requisition's own journal balance, not the total journal-entry count. **This is verifiable directly
from live data**: pull `journal_entries`/`journal_lines` for a real reconciled requisition and
confirm whether the expense account was debited once or twice.

**FACT (re-verified, still true) — direct-payment approval has no urgency/float/repeat-offender
signal, only a free-text reason field.** No such signal exists in `DirectDisbursementRequest`.

**FACT (re-verified, still true) — "who held the money" resolves to whoever recorded the top-up**,
not a physical custodian field. `PettyCashTopUp` has no separate `custodian_id`/`held_by` column.

### C.2 Direct Disbursement Request (exceptional petty-cash payment, no prior requisition)

A genuinely distinct model/status machine (`pending_approval → processing → approved|rejected`),
approved via a different permission than ordinary requisition approval, with the same self-approval
block. A real, identifiable code path.

### C.3 SpendVoucher / Payment Voucher — the reference pattern

**FACT.** The one place in the codebase with a genuine three-distinct-permission
maker/checker/poster control: create, approve (blocks requester approving own), post (blocks both
requester and approver). Posting mints the cash fact first, then the GL entry, then the fee leg —
all in one DB transaction. `eligibleLiabilities()` distinguishes `funding_mode` = `unpaid_invoice`
(supplier) vs `out_of_pocket` (staff reimbursement) as a real, queryable field, not just a label.
**Recommend this exact pattern be replicated for Payroll (C.4) and elsewhere.**

### C.4 Payroll: prepare → lock → mark-paid

**FACT (re-verified) — prepare, lock, and mark-paid all sit behind exactly ONE permission**,
`HR_MANAGE_PAYROLL`. **FACT (nuance) — there IS a real identity-based self-succession guard**:
`lock()` refuses if the locker created the run; `markPaid()` refuses if the payer locked it; both
mirror SpendVoucher's pattern and log the override when used. **Net assessment: a materially
weaker control than SpendVoucher's** — any two distinct permission-holders can complete a full
prepare→lock→pay cycle between them; the permission model itself does not force a third distinct
role. **RISK: MEDIUM-HIGH.**

### C.5 Staff Advance (salary advance) — the weakest link found in this pass

**ISSUE — RISK: HIGH.** `SalaryAdvanceController::approve()` only creates future payroll-deduction
ledger rows and flips status to `approved` — it **never creates a `Payment`, references a
`PaymentSource`, or posts a `JournalEntry`.** `SalaryAdvanceRequest` has no `payment_id`/
`disbursement_id` field of any kind. The actual cash handover to the employee has **zero trace in
the ERP** — if Finance pays it via petty cash or a SpendVoucher, that transaction carries no
back-reference to the advance it satisfies; an advance could be approved and never paid, or paid
twice through two different rails, and the system would not notice either way.

### C.6 Reimbursable expense (staff out-of-pocket claim)

**FACT.** A real, distinct, identifiable state — `SpendVoucher.type` constrained to
payment/reimbursement, `funding_mode` computed per cost line, claimant identity carried alongside,
and a distinct GL description generated for staff reimbursement vs supplier payable.

### C.7 Supplier expense / Direct bill / "Cash purchase"

**FACT.** Direct Bill is real, enforced, and distinct (see B.5). **FACT — "cash purchase" (a
walk-in till-counter purchase) is NOT a distinct concept anywhere** — recorded identically to any
other petty-cash disbursement, distinguishable only by `payment_method='cash'`.
**REQUIRES WNG CONFIRMATION** whether finer-grained control/reporting is needed here.

### C.8 Project vs. administrative/overhead expense

**FACT.** Real and enforced, not just naming. `ExpenseCode.job_id_rule` is checked in both
`CostCollectorService::validate()` and `PettyCashService::createDisbursement()`; seed data shows
deliberate overrides marking specific codes as overhead-only ("this is overhead, not a job cost").

### C.9 Summary table — expense types found

| Expense type | Distinct model/status/code path? |
|---|---|
| Project expense (site/materials via petty cash) | Yes |
| Administrative/overhead expense | Yes |
| Petty cash expense | Yes — full lifecycle |
| Reimbursable (staff out-of-pocket) | Yes |
| Supplier expense (PO-backed bill) | Yes |
| Supplier expense (direct/no-PO bill) | Yes |
| Cash purchase (till counter) | **No** — indistinguishable from any other cash Payment |
| Staff/salary advance | Partially — request/approval exists, **no linked disbursement record** |
| Direct/exceptional disbursement (no requisition) | Yes |
| Payroll expense | Yes as accrual; payment leg has no `Payment` record (see Part D) |

---

## PART D — Payments and Receipts

### D.1 The intended architecture: three concepts, mostly separated

**FACT.** `Payment` is designed as the canonical money-movement row ("one outgoing payment,
whatever account it left"), linking to a business document polymorphically and to specific cost
lines via allocations. `PaymentSettlementService::settle()` is documented as "the single cash-side
settlement engine used by every outgoing payment rail" and genuinely is used by SpendVoucher and
supplier bill payment.

**ISSUE — RISK: MEDIUM, the "single engine" claim is false.** `PettyCashService::createDisbursement()`
— behind ordinary requisition disbursement and direct-disbursement approval — does **not** call
`PaymentSettlementService` at all; it independently re-implements idempotency checking, balance
locking, cap checking, top-up allocation, and ledger posting. Two separately maintained rule sets
for the same underlying operation.

### D.2 Where the three concepts are cleanly separated (positive findings)

- **Supplier payment:** `Bill` (document) → `BillPayment` (allocation) → `Payment` (real movement,
  possibly settling several bills) → `JournalEntry`, triggered from `BillPayment::boot()` so no
  caller can create cash movement against an unverified invoice or skip the GL leg.
- **Customer receipt:** `ClientReceipt` (movement) → `EnquiryPayment` (allocation, own status/
  journal_entry_id) → `ProjectInvoice` (document, own journal_entry_id for revenue). Deliberately
  guards against counting a pending/reversed `EnquiryPayment` as real cash.
- **Payment Voucher:** document (`spend_vouchers`) → `Payment` (minted at post time) →
  `JournalEntry`. Enforced by the 3-permission maker/checker/poster split.

### D.3 Where the three concepts get mixed — worst first

**ISSUE (CRITICAL) — Payroll's cash-out event has no money-movement record at all; the business
document's own status/reference fields ARE the payment record.** `PayrollRunController::markPaid()`
posts one `JournalEntry` and writes `payment_journal_entry_id`/`payment_source_id`/`payment_date`/
`payment_reference` **directly onto the `PayrollRun` row** — creating **no `Payment` row, no
`BillPayment`-equivalent, no `CashMovement` row.** An entire month's net payroll — potentially
WNG's single largest cash outflow — is **invisible** to every Payment-based control:
`FundCustodyService`, `PaymentReversalService`, bank-reconciliation views, and any future
"list every cash-out event" report. Notably, the same author's code comment shows they are aware
of and have already fixed this exact pattern for petty cash's *journal-writing* half ("two
writers... is exactly what produced a second, unreconciled ledger... once already") but have not
extended the fix to payroll's *cash-movement* half.

**ISSUE (HIGH) — Salary advance disbursement has the same gap** (see C.5): `status='approved'` is
the entire representable state of "the advance was paid"; no `Payment` row exists to check it
against.

**ISSUE (LOW-MEDIUM) — SpendVoucher pre-declares money-movement-shaped fields on the business
document itself** (`net_cash_paid`, `payment_reference`, `payment_method`, `transaction_cost`) —
duplicative of what the real `Payment` row records, mitigated because `post()` always mints one.

**ISSUE (LOW) — `Bill.status` is a cached/derived field** kept in sync only via Eloquent model
events — a drift risk if a future write path to `bill_payments` skips `updatePaymentStatus()`.

**ISSUE (LOW) — a fourth, independent money-movement table, `CashMovement`,** exists for manual/
generic bank entries — each of Payment/BillPayment/ClientReceipt/CashMovement occupies a genuinely
distinct niche (no silent overlap found), but "every cash movement" requires a 4-table union.

### D.4 Summary table — money movement representation

| Flow | Business document | Money movement | Accounting posting | Cleanly separated? |
|---|---|---|---|---|
| Supplier bill payment | Bill | Payment (+ BillPayment) | JournalEntry via `postSupplierPayment()` | Yes |
| Customer receipt | ProjectInvoice | ClientReceipt (+ EnquiryPayment) | JournalEntry (both) | Yes |
| Payment Voucher | SpendVoucher | Payment | JournalEntry | Yes, minor field duplication |
| Petty cash disbursement | PettyCashRequisition | Payment (2nd, parallel engine) | JournalEntry (advance) + per-line + surrender clearing (possible double-post) | Partially |
| Payroll payment | PayrollRun | **None** | JournalEntry via `postPayment()` | **No** |
| Salary advance | SalaryAdvanceRequest | **None at all** | None (only future deduction booked) | **No** |
| Manual bank entry | — | CashMovement | JournalEntry | Yes, but a 4th parallel ledger |

---

## PART E — Project Finance

### E.1 The chain, as it actually exists

```
Quotation/expected revenue   quote_approvals.quote_amount (approved row)
Budget (planned cost)        task_budget_data JSON → BudgetProjector → CostLine (nature=planned)
Procurement/materials cost   ProcurementCostProducer / StoresCostProducer post CostLine actuals
Supplier tax on that cost    CostTaxPricer + TaxResolver
Labour cost                  NOT attributed to a project — see ISSUE 11-A
Transport/logistics cost     NOT attributed to a project as its own actual — see ISSUE 11-B
Invoicing (billing)          ProjectInvoice + ProjectInvoiceLine, priced by InvoicePricer
Revenue recognition          ReceivablesPostingService::postInvoiceIssued()
Receipts                     postClientReceipt() then postInvoiceAllocation()
Actual cost / cost of sales  WorkInProgressReleaseService::releaseForInvoice() (matching principle)
Margin / profitability       CostAccountService::marginAgainstJournals()
```

### E.2 Is profitability computed from actual transactions, or a cached/duplicated value?

**FACT — the authoritative, single-project margin figure IS computed live from posted accounting
transactions, not a cached total.** `marginAgainstJournals()` sums billed revenue from
`project_invoices` where `journal_entry_id IS NOT NULL` (unposted invoices contribute nothing) and
cost-of-sales from released `journal_lines` (or verified actuals if nothing released yet). Cost and
budget share one table (`cost_lines`, `nature` column), so variance is a `GROUP BY`, not a
reconciliation between two systems that can drift. **This is a genuinely well-architected result —
KEEP as the model for closing the gaps below, not a candidate for replacement.**

### E.3 ISSUE 11-A: Labour cost is never attributed to a project — RISK: HIGH

**ISSUE.** Payroll splits gross pay `direct`/`indirect` **by department**, posted as one aggregate
journal with no `project_enquiry_id` on either leg. The code's own comment states the design intent
explicitly: *"nobody records hours against jobs, and an invented allocation would produce job
margins that look precise and are fiction."* `BudgetProjector` does project a planned labour
category per project, but no cost producer ever posts an actual labour cost line against it — every
project's labour row permanently shows `spent = 0.00`. **Every reported project margin is
systematically overstated by whatever labour was actually consumed.**

**CLASSIFICATION: REQUIRES WNG CONFIRMATION.** Not a bug to silently fix — the code's reasoning not
to invent an allocation is sound. WNG must choose: (a) start capturing time/labour against jobs, (b)
treat labour deliberately as absorbed overhead and remove/relabel the "labour" budget category, or
(c) apply a documented, explicitly-labelled standard-cost estimate.

### E.4 ISSUE 11-B: Transport/logistics actual cost — same structural gap, RISK: MEDIUM

**ISSUE.** `BudgetProjector` projects a planned logistics category, but no cost producer ever posts
an actual/committed/accrued logistics cost line against it — transport cost captured via petty cash
shows up as **unbudgeted spend** rather than against its own budget line.
**CLASSIFICATION: REQUIRES WNG CONFIRMATION / KEEP&IMPROVE** — a smaller fix than labour if WNG
confirms no existing tagging mechanism is already doing this.

### E.5 ISSUE 11-C: Portfolio-wide cost accounts view has no billing/margin column — RISK: MEDIUM

**ISSUE — CONFIRMED.** `CostAccountService::index()`/`grandTotals()` query only `cost_lines`; the
frontend's 12-column portfolio table has none of them billing/revenue/margin. The single-project
drill-down computes margin correctly (E.2) — it was simply never lifted into the list view. A
Finance user cannot sort/scan "which of our 40 live jobs is running at the worst margin" from one
screen. **CLASSIFICATION: KEEP&IMPROVE.**

### E.6 ISSUE 11-D: Client-computed, unvalidated JSON total gates workflow — RISK: LOW-MEDIUM

**FACT/ISSUE.** `task_budget_data.budget_summary.grandTotal` is computed **client-side** and saved
as-is, with no server-side recomputation. It gates the Budget task's "Complete Task" workflow AND
feeds the pre-approval margin-warning signal a quote approver sees. Mitigating: this is explicitly
the advisory, pre-authoritative layer — not what `CostAccountService`'s real margin is built from —
except the workflow-completion check *is* a gate, and it is gated on this unverified number.
**CLASSIFICATION: KEEP&IMPROVE** (recompute server-side before trusting it for either purpose).

### E.7 ISSUE 11-E: Hardcoded VAT rate inside project-margin advisory code — RISK: LOW

**ISSUE.** `QuoteInsightsService::budgetComparison()` hardcodes `$netAmount = $quoteAmount / 1.16`,
directly contradicting `TaxResolver`'s own documented rule that "nothing here may hardcode 16%, 5%
or 3%." If Finance ever changes the standard VAT rate in `vat_treatments`, this one advisory
calculation silently keeps using 16% forever. **CLASSIFICATION: KEEP&IMPROVE.**

### E.8 Other chain links — verified sound

**FACT.** Revenue recognition is three explicit, independently-reversible ledger events, a material
improvement over the pre-2026-09-08 state (documented in code: *"Issuing an invoice changed a
status field... neither reached the ledger"*). **KEEP.**

**FACT.** Cost-of-sales recognition is matched to billing, not a calendar date, specifically to
avoid a measured "21% of jobs cross a month end" distortion; release is proportional, idempotent,
and correctly nets out reversals. **KEEP** — genuinely sophisticated, correctly-reasoned accounting
logic.

**FACT.** Materials cost — the highest-volume category — flows end-to-end from budget through
procurement/stores into verified cost lines with full VAT/WHT treatment, consumed against their
planned line so variance is a `GROUP BY`. The one link in the chain that is fully built and
reconciles.

---

## PART F — Attachments and Supporting Documents

### F.1 No generic Attachment/Document model exists

**FACT.** A repo-wide search shows every module that stores files has its own bespoke model
(`TaskAttachment`, `DesignDocument`, `EmployeeDocument`, etc.) — none shared with Finance. There is
no `attachments` table, no polymorphic `morphMany` attachment relation anywhere in
`app/Modules/Finance`. `Payment::sourceDocument()` is the only `MorphTo` in the module, and it
answers "what triggered this payment," not "what evidence backs it." **CLASSIFICATION: REDESIGN**
(a shared, FK-backed attachment mechanism does not exist; it would need to be built).

### F.2 How each transaction type actually stores evidence today

| Transaction type | Evidence mechanism | Linkage |
|---|---|---|
| Cost Collector cost line | `cost_lines.evidence` JSON column | Column on the row, not a table/FK |
| Petty cash surrender item | `receipt_path`, single string column | Column on the row, 1:1, no multi-file |
| Client receipt | `evidence_path`, single string column | Column on the row |
| Supplier bill | **None** | N/A |
| Purchase order | **None** | N/A |
| Spend/Payment Voucher | None on the voucher itself | Indirect via allocated cost lines |
| Journal entry | **None** | N/A |

**ISSUE — RISK: MEDIUM.** Supplier bills and purchase orders have zero capacity to attach the
actual invoice PDF/scan, delivery note, or signed PO copy. Verification is recorded as a fact/
decision, but the source document verified against is never retained — real exposure for KRA
input-VAT claims and supplier disputes (both explicitly named in the codebase's own migration
comments).

**ISSUE — RISK: LOW.** The cost-evidence upload endpoint writes the file with **no DB row at all**
(deliberate, to survive a flaky mobile connection) — a submission that is abandoned leaves a
genuinely orphaned file with no cleanup job found anywhere. Once a cost line is actually submitted,
paths are validated for ownership/existence, but the JSON-array design means there is no way to
query "which cost lines reference file X," and editing a cost line's evidence array does not delete
superseded files.

**ISSUE — RISK: MEDIUM.** Cost-evidence receipts (which may show a supplier's name, amounts,
sometimes a KRA PIN) are stored on the **public** disk, reachable via a guessable/enumerable URL
once `storage:link` is in place — protected only by path unguessability, not authentication. The
team has already fixed this exact problem class for a different document type (quote Excel files,
via `MigrateQuoteExcelToPrivateDisk`), but has not applied the same private-disk + signed-URL
pattern to Finance evidence.

**CLASSIFICATION summary:** Cost Collector evidence — **KEEP&IMPROVE** (needs private-disk storage,
a real child table, orphan cleanup). Bill/PurchaseOrder evidence — **REDESIGN** (does not exist).
Overall Finance attachment architecture — **REDESIGN**.

---

*Consolidated risk ratings for every ISSUE above appear in `10_FINANCE_RISK_REGISTER.md`.
Consolidated WNG questions appear in `12_WNG_CONFIRMATION_QUESTIONS.md`.*
