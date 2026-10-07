# 76 — Cross-Module Financial, Procurement, Stores & Project Cost Flow Audit

**Date:** 2026-10-07
**Mode:** Audit, trace and verify only. No fix was implemented.
**Code audited:** `ERP-Backend` `master@376a162`, `ERP-Frontend` `master@3bade6f` (both clean before and after).
**Boundary kept:** no production access, no Finance cutover, no `--execute` chart completion, no account classification, no deployment, no W8 work, no production code changed. The dev and rehearsal databases were read with `SELECT` only.
**Verdict:** CROSS-MODULE FINANCIAL & COST FLOW AUDIT COMPLETE — IMPLEMENTATION PLAN REQUIRED (§33).

How to read the evidence tags used throughout:

| Tag | Meaning |
|---|---|
| **[PROBE]** | Reproduced by running the code in a temporary test on an isolated test database. The test was deleted afterwards. |
| **[DATA]** | Observed in rows of the dev database (`db`), the real-data rehearsal database (`wng_target_rehearsal`) or the production source copy (`woodnork_erpsystem`). |
| **[CODE]** | Established by reading the current code. Not executed. |
| **[NOT TRACED]** | Not verified in this report. Stated so nobody reads silence as a pass. |

---

## 1. Executive summary

The four modules are connected by one sound idea and several unfinished joints.

**The sound idea.** Every project cost is a row in `cost_lines`, written by one service (`CostCollectorService`), in one of four stages: planned, committed, accrued, actual. Every ledger entry goes through one funnel (`JournalPostingService::postBalancedEntry`), which enforces an open period, postable accounts, balance and a unique entry number. Every outgoing payment is one `Payment` row. The first two doors are truly single: no live code outside them writes cost lines or journals ([CODE], §3). Payments have two creators, the settlement service and the older petty-cash disbursement path (§13).

**What works end to end, verified.**
- Stocked purchase for a project: order commits, receipt accrues, Stores issue makes the actual, the bill moves the liability to Accounts Payable, the payment clears it. One actual cost per issue.
- Financial requisition: payment is an advance and creates no project cost; each accepted surrender item creates one actual; the clearing journal is the only posting.
- Supplier bill: three-way match, staged billing cap, duplicate invoice control, preparer cannot verify.
- Client side: receipt lands in Client Deposits on verification; revenue is recognised on invoice issue, never on receipt.
- Journals: zero unbalanced, zero without lines, zero without a source in both databases ([DATA], §27).

**What is wrong.** Nine findings can produce wrong money, ledger, stock value or project cost today (P0). The two that were reproduced:

1. **Reversing a stock issue debits Accounts Payable instead of Inventory** [PROBE]. The reversal's journal is Dr 2100 Accounts Payable / Cr WIP. Inventory is never restored in the ledger, and Accounts Payable is reduced by a stock movement.
2. **Reversing a payment from the Finance screen leaves its project cost and its journal standing** [PROBE]. The payment is voided and the petty-cash float is restored, but the job still shows KES 4,500 actual and the ledger still shows the float credited.

The other seven are structural gaps between modules:

3. The receipt accrual is never trued up to the supplier invoice. On the rehearsal purchase, KES 1,379.32 of VAT is stranded in Accrued Expenses and the same amount is overstated in Inventory [DATA].
4. Purchase lines that are not stock (services, custom items) are debited to Inventory at receipt and never become a cost of anything [CODE][DATA].
5. Stock received outside a goods receipt note reaches no ledger. On dev, 13 of 15 receipts have no GRN, and the ledger's Inventory account stands at −400 against a Stores valuation of 305,310 [DATA].
6. Damage write-offs, issues with no project, and reversals of receipts and adjustments change stock with no accounting entry [CODE].
7. Eight listeners in the cost chain are queued. The production source copy holds 1,088 jobs that were never processed, the oldest from January 2026 [DATA]. If the target runs the same way, order commitments, receipt accruals, petty-cash costs and void reversals never post, silently.
8. Project margin subtracts VAT-exclusive cost from VAT-inclusive invoice totals, so it is overstated by the output VAT [CODE].
9. A cost captured as "company paid" credits a cash or bank account in the ledger with no `Payment` record and no petty-cash cashbook entry [CODE].

**Counts:** P0 = 9, P1 = 14, P2 = 12, P3 = 6 (§29).

**Not everything needs code.** Findings 3, 4 and 6 cannot be fixed correctly until WNG answers accounting questions (§32): whether order prices are VAT-exclusive, where a price difference goes, and what a non-stock purchase and a write-off debit.

**First recommended phase:** 76A — P0 accounting and cost integrity (§30).

---

## 2. System architecture map

### 2.1 The main chain

```
PROJECT (project_enquiries / projects)
  │
  ├─ Budget task completed ──► BudgetProjector ──► cost_lines  nature=planned   (no ledger)
  │
  ├─ PROCUREMENT ROUTE
  │    Purchase Requisition (requisitions)            approval: approveRequisition
  │      └► Purchase Order (purchase_orders)          approval: approveOrder (+ senior)
  │            └► event PurchaseOrderApproved ─[queued]─► cost_lines committed (no ledger)
  │      └► Goods Receipt Note (goods_receipt_notes)  dock acceptance
  │            └► event GoodsReceiptRecorded ─[queued]─► cost_lines accrued
  │                                                      JE-CL: Dr Inventory / Cr Accrued Expenses
  │            └► Stores completes line ──► inventory_logs check_in ──► stocks, unit cost
  │      └► Supplier Bill (bills)                     verify: three-way match
  │            └► JE-BILL: Dr Accrued Expenses (+ Dr Input VAT, Cr WHT) / Cr Accounts Payable
  │      └► Bill payment (bill_payments + payments)
  │            └► JE-BPAY: Dr Accounts Payable / Cr paying account
  │
  ├─ STORES ROUTE
  │    Stock ──► Issue (inventory_logs check_out) ──► outbox (stores_finance_postings, synchronous)
  │            └► cost_lines actual  'stock-issue'    JE-CL: Dr WIP or expense / Cr Inventory
  │    Return ──► cost_lines actual (negative) 'stock-return'      legs swapped
  │    Reversal ► cost_lines actual (negative) 'stock-issue-reversal'   ← wrong credit side, §26 P0-1
  │
  ├─ FINANCIAL REQUISITION ROUTE
  │    Requisition (petty_cash_requisitions) ─► approval ─[queued]─► cost_lines committed
  │      └► Payment per receiver ──► JE-PCA: Dr advance account / Cr paying account   (no cost)
  │      └► Surrender reconciled ──► cost_lines actual per accepted item (analytical)
  │                                  JE-PCS / JE-PCS-R: Dr expense (+VAT) / Cr advance account
  │
  ├─ DIRECT ROUTES
  │    Direct supplier bill (no order) ─► verify ─► cost_lines actual 'direct-bill' (analytical)
  │                                      JE-BILL: Dr expense code account / Cr Accounts Payable
  │    Direct petty-cash payment with a job number ─[queued]─► cost_lines actual
  │                                      JE-CL: Dr expense / Cr paying account     ← payment IS the cost
  │    Manual cost capture (Cost Collector) ─► verification ─► cost_lines actual
  │                                      JE-CL: Dr expense / Cr AP, or Cr paying account (company paid)
  │      └► Payment Voucher (spend_vouchers) ─► Payment ─► JE-SV: Dr AP / Cr paying account
  │
  ├─ LABOUR ROUTE
  │    Labour actual ─► Project Officer verify ─► Finance verify ─► cost_lines actual (analytical)
  │    Payroll run ─► payroll journals (company expense; the only ledger posting for labour)
  │
  └─ REVENUE ROUTE
       Quote approval ─► Client invoice issued ─► JE-INV: Dr Receivable / Cr Revenue, Cr Output VAT
                                                 + WIP release: Dr Cost of Sales / Cr WIP (by billed share)
       Client receipt verified ─► JE-RCPT: Dr bank / Cr Client Deposits
       Allocation to invoice ─► JE-ALLOC: Dr Client Deposits / Cr Receivable

REPORTING
  cost_lines (verified) ─► CostAccountService ─► project cost account, margin, portfolio
  journal_lines ─► trial balance, P&L, balance sheet, cash reports, reconciliation
```

`[queued]` marks a listener that implements `ShouldQueue`. See P0-7.

### 2.2 Authoritative service per stage

| Stage | Authoritative code | Table |
|---|---|---|
| Planned cost | `BudgetProjector` → `CostCollectorService::postPlanned` | `cost_lines` |
| Commitment | `ProcurementCostProducer::postPurchaseOrder`, `PettyCashCostProducer::commitFor` / `syncReceiverCommitment` | `cost_lines` |
| Accrual | `ProcurementCostProducer::postGoodsReceipt` | `cost_lines` + journal |
| Actual | `StoresCostProducer`, `SurrenderCostPoster`, `BillController::recordDirectBillCost`, `PettyCashCostProducer::postFor`, `CostVerificationService::verify`, `ProjectLabourActualService::financeVerify` | `cost_lines` (+ journal where the line posts itself) |
| Stock movement | `StockMovementPoster` → `InventoryService::adjustStock` (rolls: `ConsumableUnitService`) | `inventory_logs`, `stocks` |
| Money out | `PaymentSettlementService::settle`; petty-cash disbursements use `PettyCashService::createDisbursement` | `payments` |
| Money in | `FinanceService::logPayment` / `verifyPayment` | `client_receipts`, `enquiry_payments` |
| Ledger | `JournalPostingService::postBalancedEntry` | `journal_entries`, `journal_lines` |
| Reversal of a journal | `JournalPostingService::reverseEntry` | new entry, `reversal_of_id` |

### 2.3 Alternative paths that bypass part of the chain

| Path | What it skips | Consequence |
|---|---|---|
| Stock receipt with no GRN (`/movements` receive, `/check-in`, quick create and receive) | Order, accrual, ledger | P0-5 |
| Purchase line with no library material | Stores, issue | P0-4 |
| Direct petty-cash payment with a job number | Requisition, surrender | Payment is the cost; see §13 |
| Cost capture "company paid" | `Payment`, cashbook | P0-9 |
| Issue with no project and no reference | Cost line, ledger | P0-6 |
| Materials bought with a financial requisition, then received into Stores | Nothing links the two | P1-14: costed twice |

---

## 3. Authoritative sources of truth

| Fact | Source of truth | Copies and projections | Status |
|---|---|---|---|
| Project cost by stage | `cost_lines` where `status = verified` | Cost account screens | Single source. Verified: only `CostCollectorService` and `CostTransferService` create rows ([CODE]; one console simulator also does, `SimulateFinanceWorkflowsCommand`). |
| Ledger | `journal_entries` / `journal_lines` | Reports | Single funnel. `writeEntry()` in `JournalPostingService` is a second, unused private writer (dead code). |
| Money out | `payments` | `bill_payments` (allocation of a payment to a bill), petty-cash `ledger_entries` (custody) | One model. `bill_payments` still posts its own journal (`JE-BPAY`) from a model event. |
| Money in | `client_receipts` (the transfer) and `enquiry_payments` (its allocation to a project) | — | Clear split. |
| Stock quantity | `stocks.quantity_on_hand` | `inventory_logs.balance_after` | Mismatch seen in data, §27 row 39. |
| Stock value | `library_materials.unit_cost` (weighted average) × quantity; rolls carry `movement_value`; boards carry `current_value` | Ledger Inventory account | **Two valuations that do not agree and are not reconciled by any posting.** P0-5, P1-8. |
| Supplier liability | Ledger Accounts Payable; `bills.balance` | `cost_lines.settled_by_bill_id` flag | Bill balance and ledger agree for matched bills; flag is coarse (P1-13). |
| Project identity | `project_enquiries.id` and its `job_number` | `projects.id`, display job strings on requisitions, `inventory_logs.reference_no` | Producers resolve to the enquiry. Purchase requisitions carry no enquiry id (P1-9). |
| Approved budget | Completed budget task → planned cost lines | `task_budget_data` JSON | Projection, re-run on change through a queued listener. |

---

## 4. Project cost lifecycle

### 4.1 What each stage means in the current code

| Stage | Meaning | Counts toward | Reaches the ledger |
|---|---|---|---|
| **planned** | A line of the approved budget | Budget | No |
| **committed** | Promised, not yet received or spent | "Committed" on the cost account | No |
| **accrued** | Goods accepted, supplier not yet invoiced | "Spent" on the cost account (actual + accrued are added together); **not** margin | Yes: Dr Inventory / Cr Accrued Expenses |
| **actual** | The cost has been incurred by the job | "Spent" and margin | Yes, unless another document owns the posting (surrender, direct bill, labour) |

A line counts only when `status = verified`. Negative actual lines are credits (returns, reversals).

### 4.2 What creates, releases and reverses each stage

| Stage | Created by | Released by | Reversed by |
|---|---|---|---|
| planned | Budget task completion (`BudgetProjector`) | A revised budget line supersedes it (old row set `reversed`, key renamed) | Same |
| committed | PO approval, one line per order item; financial requisition approval | Goods receipt (releases the order item's commitment and re-posts the unreceived balance); requisition payment (legacy) or accepted spend, return, release (receiver-paid); requisition edited back to pending; PO amendment | `releaseCommitment` sets `status = reversed`. No journal exists to reverse. |
| accrued | Goods receipt acceptance, valued at accepted quantity × order unit price | First Stores issue of the same material to the same project (`relieveAccrualsFor`) | Status only. **The journal is deliberately left posted.** |
| actual | Stores issue; accepted surrender item; verified direct bill; direct job-costed payment; verified manual capture; Finance-verified labour | Never released. WIP is moved to Cost of Sales in the ledger by billing share. | `CostVerificationService::reverse` (status + reversing journal); Stores return and issue reversal post a negative line |

### 4.3 The questions the brief asks

| Question | Answer in the current code | Evidence |
|---|---|---|
| Transaction cancelled | Purchase requisition: hard delete, only before an order exists. Purchase order: no cancel or close action exists at all. Financial requisition: editing returns it to pending and releases the commitment. | [CODE] |
| Project changes | `CostTransferService` moves a cost between projects (out line + in line). A revised budget re-labels its own history. | [CODE], not exercised here |
| Item returned to Stores | Negative actual, proportional to the returned quantity, capped by the reviewer's recovery value. Journal: Dr Inventory / Cr WIP. | [CODE] + tests |
| Item returned to supplier | **No process exists.** | [CODE] |
| Payment reversed | Journals sourced to the payment, its voucher and its bill payments are reversed; bill status recalculated; cashbook credited. **A cost line sourced to the payment is not touched** unless the void came through the petty-cash service. | [PROBE] P0-2 |
| Bill reversed | **Not possible.** No reversal and no supplier credit note. | [CODE] P1-4 |
| GRN reversed | Not possible. Update is refused. Delete is Super Admin only and refused once it reached the cost ledger, the shelf or a bill. | [CODE] |
| Stores issue reversed | Stock restored; negative actual posted; **journal debits Accounts Payable**. | [PROBE] P0-1 |
| Stores receipt reversed | Stock removed; GRN line reset to "awaiting Stores"; accrual and its journal untouched. | [CODE] |
| Accountability reversed | `PettyCashSurrenderReversalService` reverses the clearing journal; the requisition reopens. | [CODE] + tests |

---

## 5. Single Economic Cost verification

### 5.1 Matrix

| Workflow | Commitment event | Accrual event | Actual cost event | Payment event | Reversal event |
|---|---|---|---|---|---|
| Stocked purchase for a project | PO approval | GRN acceptance | **Stores issue** | Bill payment | Issue reversal or return (negative actual) |
| Stocked purchase for general stock | PO approval (department) | GRN acceptance (department) | **Stores issue** to whichever project takes it | Bill payment | Same |
| Non-stock purchase line | PO approval | GRN acceptance | **None. Stays accrued for ever.** | Bill payment | None |
| Direct supplier bill | None | None | **Bill verification** | Bill payment | None exists |
| Financial requisition, paid by receiver | Approval, restated as money is accounted for | None | **Accepted surrender item** | Receiver payment (advance) | Surrender reversal |
| Financial requisition, single payment (legacy) | Approval, released at payment | None | **Surrender reconciliation** | Disbursement (advance) | Surrender reversal |
| Direct petty-cash payment with a job | None | None | **The payment itself** | Same event | Void through petty-cash service |
| Manual cost capture | None | None | **Verification** | Voucher payment, or none (company paid) | Cost reversal |
| Labour | Budget labour line (planned) | None | **Finance verification of the labour actual** | Payroll | Reversing pair on correction |

Each workflow has exactly one actual-cost event. That part of W6 holds.

### 5.2 Can one purchase be counted twice?

| Combination | Result | Evidence |
|---|---|---|
| PO + GRN | No. Receipt releases the order item's commitment before accruing. | [CODE] + tests |
| GRN + Stores issue, same project | No. First issue retires the accrual. | [CODE] |
| GRN for project A + issue of that stock to project B | **Yes, in the "spent" figure.** A keeps the accrual for ever, B gets the actual. | [CODE] P1-1 |
| GRN + supplier bill | No project cost from a PO-backed bill. | [CODE] |
| Bill + payment | No. A bill-linked payment is skipped by `postFor`. | [CODE] + tests |
| Bill + payment voucher | No. Refused in three places for GRN accruals; voucher settles liabilities only. | [CODE] + tests |
| Requisition payment + surrender | No. Payment is skipped as an advance. | [CODE] + tests |
| Direct bill + manual capture of the same invoice | No, when supplier and invoice number match. Guarded in both directions. | [CODE] |
| **Financial requisition surrender + Stores issue of the same goods** | **Yes.** Materials bought with requisition cash are an actual at surrender. If they are then received into Stores (no GRN is needed) and issued, the issue is a second actual. Nothing links the two. | [CODE] P1-14 |
| Surrender receipt + supplier bill for the same invoice | Not prevented. The receipt check looks at surrender items and payments; the bill check looks at bills. | [CODE] P2-9 |
| Labour actual + payroll | No ledger double count: the labour line is analytical. The job's ledger WIP never contains labour. | [CODE] |

---

## 6. Procurement → Finance

| Check | Finding |
|---|---|
| Purchase requisition source of truth | `requisitions` + `requisition_items`. Update deletes and recreates every item. Any signed-in user can create one. |
| PO source of truth | `purchase_orders` + items. Editable only while pending or returned for correction. |
| Approved PO immutability | Holds. Changes go through an amendment with its own approval; an item-changing amendment is refused once a receipt or bill exists. |
| PO amendment and commitment | Old commitments released, new ones posted under a fresh key. Tested. |
| Supplier, project, department | Supplier from the order. Project resolved from the requisition's project. Department from the requisition. |
| Quantities and prices | Receipt cannot exceed the order line. Accrual = accepted quantity × order unit price. |
| VAT and WHT | **Captured only on the bill.** The order and the receipt carry no tax. Whether an order price includes VAT is not stated anywhere in the data. P0-3. |
| Partial receipt | Supported. Unreceived balance is re-committed. |
| Partial and multiple bills | Supported, capped at the order's remaining billable net value. |
| Duplicate bills | Same supplier + same invoice number refused; override needs a permission and a reason. |
| Bill exceeding receipt | Three-way match caps the bill at what Stores accepted [NOT TRACED in detail; covered by `PurchaseFlowTest`, `StagedBillingTest`]. |
| Payment, partial, multiple | One `Payment` per transfer, allocated to one or more bills. Fee posted once to bank charges. |
| Credit notes, returns to supplier | **Missing.** P1-4. |
| Cancellation, closure | **Missing for orders.** A short-delivered order keeps its remaining commitment for ever. P1-2. |
| Emergency purchase | No separate path. A direct bill or a financial requisition is the route. |
| Same amounts in both modules | Procurement Billing and Finance Payables read the same `bills` rows through the same workflow service. Two screens, one record (P2-2). |
| Manual re-entry between modules | Bill amount and tax are typed from the invoice (expected). Receipt price can be retyped by Stores (P1-8). |

**The accounting gap in this chain (P0-3).** The receipt posts Dr Inventory / Cr Accrued Expenses at quantity × order price. The bill posts Dr Accrued Expenses for the bill's **net** amount. Nothing compares the two. On the rehearsal purchase [DATA]:

```
JE-CL-0003883  Dr Inventory            10,000.00   Cr Accrued Expenses  10,000.00   (receipt, 2 × 5,000)
JE-BILL-0000001 Dr Accrued Expenses     8,620.68
                Dr Input VAT            1,379.32
                Cr WHT payable            200.00   Cr Accounts Payable   9,800.00   (invoice, gross 10,000)
```

Accrued Expenses keeps a credit of 1,379.32 that no later entry clears, and Inventory carries the stock at 10,000 although 1,379.32 of it was claimed as input VAT. The same happens for any price difference, discount or part-billed order.

---

## 7. Procurement → Stores

| Check | Finding |
|---|---|
| One receipt, not two | Holds for GRN lines. Dock acceptance only classifies the line. Stock is posted once, by Stores, from the receiving queue or from `confirmItem`, under a row lock, and the line records its `inventory_log_id`. |
| Quantity agreement | `assertGrnQuantityMatches` refuses a Stores quantity that differs from the accepted quantity (after unit conversion). The `confirmItem` path credits the inspected quantity. No mismatch in data (§27 row 22). |
| Partial and multiple GRNs | Supported. |
| Over-receipt | Refused against the order line. |
| Under-receipt | Allowed; balance stays committed. |
| Rejected or damaged at the dock | Held as `awaiting_inspection`; not accrued and not stocked until an inspection decision, which re-fires the accrual event. |
| Returns to supplier | Missing. |
| Serial, lot, expiry, boards, rolls | Routed to Stores for detail capture before stock is posted. |
| Valuation | The GRN line carries the order unit price. Stores may type a different `receipt_unit_cost` (`/movements`) or `unit_price` (`confirmItem`). The accrual stays at the order price. P1-8. |
| VAT | Not considered at receipt. |
| **Stock received with no GRN** | Fully supported by the same poster and by "quick create and receive". It updates quantity and weighted-average cost and posts **nothing** to the ledger. P0-5. |

---

## 8. Stores → Projects

**The event that creates project actual cost is the Stores issue.** Not the order, not the receipt, not the payment. Verified in code and by the existing tests.

The brief's scenario, traced through the current code:

| Event | Stock quantity | Stock value (Stores) | Project cost | Ledger | `cost_lines` |
|---|---|---|---|---|---|
| Receive 10 rolls on a GRN | +10 | 10 units created at receipt cost | None | Dr Inventory / Cr Accrued (from the GRN acceptance, not from this movement) | accrued |
| Receive 10 rolls with no GRN | +10 | Same | None | **Nothing** | None |
| Issue 3 rolls to Project A | −3 | −value of the quantity issued | +actual on A | Dr WIP / Cr Inventory | actual `stock-issue` |
| Issue 2 rolls to Project B | −2 | Same | +actual on B | Same | actual `stock-issue` |
| Return part of one roll | +returned quantity | +value | −actual, proportional | Dr Inventory / Cr WIP | actual, negative, `stock-return` |
| Record waste (damage) | −quantity | −value | **None** | **Nothing** | None |
| Reverse an issue | +quantity | +value | −actual | **Dr Accounts Payable / Cr WIP** | actual, negative, `stock-issue-reversal` |
| Count a roll, variance approved | ±variance | ±value | None | Dr/Cr Inventory against Inventory Adjustments | None |
| Count bulk stock, approved | ±variance | priced at current catalogue cost | None | Same | None |

Issue value, in order: the receipt cost frozen on the movement; else the material's weighted average; else its default price; else the approved budget rate for that project line (marked `valued_at_plan`). With none of these the cost posting fails and the outbox row is left `failed` for Finance; the stock has already left. A roll issue with no receipt value fails at the same point.

Accrual relief (P1-1): the first issue of a material to a project retires **every** accrual for that material on that project, whatever quantity was issued. The code comment records this as a deliberate conservative choice.

---

## 9. Stores → Finance

| Stores event | Inventory account | Other side | Project cost | Posted today |
|---|---|---|---|---|
| GRN acceptance | Dr | Cr Accrued Expenses | accrued | Yes (queued listener) |
| Receipt with no GRN | — | — | — | **No** |
| Opening inventory count | Dr | Cr Opening Balance Equity | — | Yes |
| Issue to a project | Cr | Dr WIP or the material's expense account | actual | Yes |
| Issue with no project | — | — | — | **No** |
| Return from a project | Dr | Cr WIP | negative actual | Yes |
| Issue reversal | **not touched** | Dr Accounts Payable / Cr WIP | negative actual | **Wrong** |
| Receipt reversal | — | — | — | **No** (accrual stays) |
| Damage / write-off | — | — | — | **No** |
| Count variance | Dr or Cr | Inventory Adjustments | — | Yes |
| Reversal of an adjustment movement | — | — | — | **No** (count journal stays) |
| Board issue | Cr | Dr WIP | actual | Yes, through the same issue event |

**Operational only, no Finance posting:** receipts without a GRN, non-project issues, damage, receipt reversal, adjustment reversal.
**Posted by Finance without Stores knowing:** the accrual for a non-stock purchase line is debited to Inventory although Stores never holds it (P0-4).
**Agreement between Stores and the ledger:** none is enforced. `/procurement-stores/ledger-reconciliation` reports the difference; nothing closes it. Dev: ledger −400, Stores 305,310. Rehearsal: ledger 12,250, Stores 10,750 [DATA].

---

## 10. Financial requisitions

Baseline: Reports 75R, 75R-A, 75R-B, 75R-C. Verified against current code, with the PettyCash suite as supporting evidence (§31).

| Rule | Verified |
|---|---|
| Payment does not create project actual cost | Yes. `PettyCashCostProducer::postFor` returns `skipped_requisition_advance` for any payment with a `requisition_id`. Journal is Dr advance / Cr paying account only. [CODE] + tests |
| Accepted accountability creates actual exactly once | Yes. One cost line per surrender item, keyed on the item; analytical; the clearing entry is the only posting. [CODE] + tests |
| Returns create no project cost | Yes by design: a return is a cash movement. [NOT TRACED line by line; covered by `RequisitionReceiverAccountabilityTest`] |
| Unused release does not change the approved amount | [NOT TRACED line by line; same test] |
| Several receivers and payments stay linked to one parent | Yes. `payments.requisition_id` + `requisition_child_reference`; reversal locks the parent first. [CODE] |
| Overspend | Held, never auto-reimbursed. The legacy surrender now refuses to post above the advance. [CODE] |

Findings in this route:

- **Silent account fallbacks (P1-10).** `surrenderItemLegs` debits "the first expense account by code" when a surrender item's expense code has no account. `postPettyCashAdvance` credits the default bank account when the paying account has no ledger account. Both are the guess-an-account pattern removed elsewhere.
- **Commitment listener is queued (P0-7).**
- **Same expense through two routes (P1-14).** Requisition cash spent on materials is a project actual at surrender. The same materials received into Stores and issued are a second actual.

Compared with procurement and Stores costs: all three land in the same `cost_lines` table with the same project identity and budget link, so they add up on one cost account. They differ in when the actual arises (surrender, issue, bill verification) and in whether the line carries its own journal.

---

## 11. Direct expenses and payment vouchers

| Path | Request | Approval | Money | Journal | Project attribution | Cost line |
|---|---|---|---|---|---|---|
| Manual cost capture, out of pocket | Reporter | Verifier (never the reporter, unless self-approve permission; recorded) | Later, by reimbursement voucher | Dr expense (+VAT, −WHT) / Cr Accounts Payable | Job or cost centre, driven by the expense code's job rule | actual at verification |
| Manual cost capture, unpaid supplier invoice | Reporter | Verifier | Later, by payment voucher | Same | Same | actual at verification |
| Manual cost capture, company paid | Reporter | Verifier | **No payment document** | Dr expense / **Cr the chosen paying account** | Same | actual at verification |
| Payment voucher | Requester | Approver, senior approver above a limit, poster; separation enforced on each step | `Payment` at posting | Dr the liability account the cost line credited / Cr paying account | From the cost lines | None (settlement only) |
| Direct petty-cash payment | Cashier | None beyond the permission | `Payment` | Dr expense / Cr float (through its cost line), or `JE-PAY` when there is no job | Job number | actual, from the payment |
| Direct supplier bill | Preparer | Verifier | Bill payment | `JE-BILL` | Bill's project or department | actual at verification |

Duplicate risk against other routes:

| Against | Control |
|---|---|
| Supplier bill | Manual capture as unpaid invoice ↔ bill: blocked both ways on supplier + invoice number. Other funding modes: not checked. |
| Financial requisition | Receipt number + amount checked against surrender items and payments. Not checked against bills or cost capture. |
| Stores issue | None. |
| Procurement cost | A GRN accrual cannot be paid by voucher (three layers). |

`company_paid` also accepts any active paying account, including one of type `payable` (Supplier Credit), because the rule checks `is_active` only.

---

## 12. Supplier bill

| Question | Answer |
|---|---|
| What creates it | A person, in Procurement Billing or Finance Payables. |
| What it references | A purchase order, or nothing (direct bill: own supplier, expense code, optional project or department). |
| Is a PO mandatory | No. Without one it is a direct bill and may not use a Direct materials expense code. |
| Is a GRN mandatory | For a PO-backed bill, yes in effect: verification requires the three-way match. |
| Can it exceed the PO | No. Net amount is capped at the remaining billable value. |
| Can it exceed the GRN | No, by the match [NOT TRACED in detail]. |
| Partial billing | Yes, staged. |
| Duplicate invoice number | Refused per supplier; override by permission with reason, recorded. |
| Credit note | **None.** |
| Reversal | **None.** A verified bill can only be corrected by reversing its journal from the ledger screen, which leaves the bill itself unchanged. |
| Payment allocation | `bill_payments`, one per bill per transfer. |
| Edit | Only after the verifier returns it, only by its preparer; logged with before and after. |
| Delete | Hard delete, allowed only when unverified, unpaid and unposted. Not logged. |

**Does the bill create cost?** A PO-backed bill: no project cost, no expense; it moves the liability from Accrued Expenses to Accounts Payable and recognises input VAT. A direct bill: yes, it is the actual cost, at net of recoverable VAT.
**Does the GRN create cost?** It creates an accrued line that counts in the project's "spent" figure but not in margin, and an Inventory debit.
**Does the Stores issue create cost?** Yes. For stocked items it is the only actual-cost event.
**Non-stock items:** nothing ever creates the actual cost (P0-4).

---

## 13. Payment

**One model:** `payments`. **Two creators:** `PaymentSettlementService::settle`, which describes itself as the single engine, and the older petty-cash disbursement path, which does not use it.

| Creator | Uses the engine | Journal | Creates project cost |
|---|---|---|---|
| Supplier bill payment (`SupplierPaymentService`) | Yes | `JE-BPAY` from a `BillPayment` model event | No |
| Financial requisition receiver payment | Yes (through `RequisitionDisbursementService`) | `JE-PCA` advance | No |
| Petty-cash disbursement (`PettyCashService::createDisbursement`, also used by the import, the offline batch and the legacy single-payment requisition) | **No.** Own path: `PettyCashRepository::createDisbursement` calls `Payment::create` directly | Through its cost line, or `JE-PAY` | **Yes, when it has a job number and no requisition** |
| Payment voucher posting | Yes | `JE-SV` | No |
| Payroll (`PayrollFinancePostingService`) | Yes | Payroll journal sourced to the run | No |
| Salary advance (`SalaryAdvanceController`) | Yes | [NOT TRACED] | No |
| Client refund | No such workflow found | — | — |

Shared properties: paying account must be payment-capable (Supplier Credit refused), amount positive, method validated, petty-cash balance and cap checked under lock, optional idempotency key, reversal through `PaymentReversalService` (refused when reconciled, when a payroll payment, when accounted for in a live surrender).

Findings:

- **Payment that is a cost.** A direct petty-cash payment with a job number is the project's actual cost. This is the one place where the brief's rule "Payment ≠ Expense" is deliberately broken. It is documented in the code as legacy behaviour for paid-and-done spend. WNG should decide whether it stays (§32).
- **P0-2.** `POST /api/finance/payments/{id}/reverse` calls the reversal service and nothing else. The cost line of a directly costed payment is reversed only by the `PettyCashDisbursementVoided` event, which only `PettyCashService::voidDisbursement` dispatches.
- **Duplicate engines (P3-6).** Two payment creators, as above. For the ledger, `postSupplierPayment`, `postDirectPayment` and `postSpendVoucher` are marked deprecated in favour of `postPayment`, which nothing calls: three live posting engines and one unused replacement.
- **P1-9.** A supplier payment takes its project from the first bill's requisition and its enquiry id from a column `requisitions` does not have, so `payments.project_enquiry_id` is always null for supplier payments.
- **Duplicate reference control** covers bill payments only.

---

## 14. Cash and bank

| Movement | Document | Ledger | Reconcilable |
|---|---|---|---|
| Supplier payment | `payments` + `bill_payments` | Dr AP / Cr source account | Yes (`finance_statement_matches.payment_id`) |
| Requisition advance | `payments` | Dr advance / Cr source | Yes |
| Petty-cash payment | `payments` + cashbook debit | Dr expense / Cr float | Cashbook |
| Voucher payment | `payments` | Dr liability / Cr source | Yes |
| Transfer fee | On the payment | Dr bank charges / Cr source | With the payment |
| Client receipt | `client_receipts` + `enquiry_payments` | Dr source / Cr Client Deposits | [NOT TRACED] |
| Returned requisition cash, other inflows and outflows | `cash_movements` | Dr/Cr source against an offset account | [NOT TRACED] |
| Petty-cash top-up | `petty_cash_top_ups` + cashbook credit | [NOT TRACED] | — |
| **Company-paid cost capture** | **None** | Dr expense / Cr source | **No.** Nothing to match; the petty-cash cashbook is not debited. P0-9 |

Every paying account is a `payment_sources` row with a ledger account, so bank, petty cash, M-Pesa and card behave the same way. One module moves money in the ledger without the settlement engine: the Cost Collector's company-paid mode.

---

## 15. Projects module

The Projects module holds the enquiry, tasks, budget, materials list and client receipts. It holds no cost screen. The only links from Projects to the cost account are inside the materials task components. Project financial position is answered in Finance → Project Costs.

| Question | Answerable today | Source | Reliable |
|---|---|---|---|
| What was budgeted | Yes | planned cost lines | Yes |
| What is committed | Yes | committed cost lines | Overstated for short-delivered orders (P1-2); missing if the queue is not processed (P0-7) |
| What has been received | Partly | accrued cost lines; GRN screens | Understated after a part issue (P1-1) |
| What has been consumed | Yes | actual `stock-issue` lines; Stores project desk | Yes |
| What has been accrued | Yes | accrued cost lines | As above |
| What has actually been spent | Yes | actual cost lines | Subject to P0-1, P0-2, P0-4, P1-14 |
| What has been paid | Partly | `payments.project_enquiry_id` | **No.** Excludes every supplier payment (P1-9) |
| What remains unpaid | No, not per project | — | — |
| What materials remain unused | In Stores, per project line | Project Materials Desk | Operational only |
| What was returned | Yes | negative actual lines; Stores | Yes |
| What labour was used | Yes, where recorded | labour actuals | Only Finance-verified ones count |
| What logistics was used | No | — | — |
| What revenue was billed | Yes | `project_invoices` | Shown VAT-inclusive (P0-8) |
| What revenue was received | Yes | `enquiry_payments` | Yes |
| Current direct margin | Yes | `CostAccountService::marginAgainstJournals` | **No** (P0-8, P1-12) |

Mixing of concepts: the cost account keeps commitment, accrual and actual in separate columns and labels cash paid as "not added to budget-versus-actual". Two mixes remain: "spent" adds accrued to actual, and margin subtracts net cost from gross revenue.

---

## 16. Project profitability

| Cost or revenue family | In project margin | Note |
|---|---|---|
| Revenue | **INCLUDED, UNRELIABLE** | Sum of invoice `total_amount`, which is subtotal + VAT. Credit notes net off. |
| Direct material from Stores | INCLUDED | Actual at issue. Value may be a default price or budget rate (P1-8). |
| Direct procurement, stocked | INCLUDED once issued | Before issue it is accrued and outside margin. |
| Direct procurement, non-stock | **NOT INCLUDED** | Never becomes actual (P0-4). |
| Direct supplier bills | INCLUDED | Actual at verification. |
| Financial requisition expenses | INCLUDED | Actual at accepted surrender. |
| Direct petty-cash payments | INCLUDED | Actual at payment. |
| Manual cost capture | INCLUDED | Actual at verification. |
| Direct labour | **PARTIAL** | Only Finance-verified labour actuals. Dropped again when margin switches to the released-WIP basis (P1-12). |
| Logistics and fleet | **NOT INCLUDED** | No connection exists (§18). |
| Transfer fees | NOT INCLUDED, by decision | Overhead. |
| Overhead allocation | NOT INCLUDED | Awaiting WNG decision. |

The API already labels the figure `margin_type: direct`, `margin_status: provisional`, `fully_loaded_available: false`, with a `cost_completeness` map. It must keep that label. It is not true profit.

**P1-12.** Margin uses cost released from WIP to Cost of Sales when any release exists, and verified actual cost otherwise. The two bases differ in content: the release is read from the ledger, and labour, direct bills coded to a non-WIP account and surrender items are not in ledger WIP for the job in the same way. The completeness map says labour is included in both cases.

---

## 17. Labour

| Step | Current behaviour |
|---|---|
| Entry | Project Officer records a labour actual against a budget labour line. |
| Verification | Project Officer verify, then Finance verify. Rate must be resolved from an authorised source. |
| Cost line | One actual per labour actual, at Finance verification, idempotent. |
| Ledger | **None from the cost line** (`postsIndependently: false`). |
| Payroll | Payroll owns the company expense and its journals, split by labour classification. |
| Duplicate prevention | The labour cost line never posts, so wages reach the ledger once, through payroll. |
| Closure | Refused on a financially closed project. |

Labour is therefore **an analytical project cost and a payroll accounting cost, linked by nothing**. Remaining gaps, all needing a WNG decision:

1. Labour never enters the job's WIP in the ledger, so Cost of Sales for a job excludes labour while the cost account includes it.
2. Nothing reconciles labour charged to jobs against payroll for the same period.
3. Casuals, overtime and work orders are outside W7 (per Report 43).
4. Payroll payment reversal is refused pending a decision.

---

## 18. Logistics and fleet

Audit only. W8 was not started.

| Cost | Captured in | Reaches Finance | Reaches a project | Reaches the cost ledger | Reaches the ledger |
|---|---|---|---|---|---|
| Vehicle maintenance | `vehicle_maintenance_logs.total_cost`, `cost_breakdown` | **No** | **No** | **No** | **No** |
| Fuel | `vehicle_inspections.amount_fueled_litres` (litres, no money) | **No** | **No** | **No** | **No** |
| Trips and deliveries | `trip_requests`, `deliveries` (no cost fields found) | **No** | **No** | **No** | **No** |
| Driver or rider | Payroll only | Through payroll | No | No | Through payroll |
| External transport | Only if someone raises a financial requisition, bill or cost capture with a transport expense code | Yes, by that route | Yes | Yes | Yes |

No file in the Logistics, logistics task, setup, setdown, Production, Printing, Design or Assets modules references the cost collector, journals or requisitions. The frontend route `/logistics/transport-costs`, titled "Transport Costs", renders the Drivers list.

---

## 19. Revenue

| Step | Behaviour | Evidence |
|---|---|---|
| Quote | Approved quote is the billing basis; a no-quote exception has its own approval fields. | [CODE] |
| Client invoice | Priced by `InvoicePricer` from lines with a VAT treatment. Checked, then issued. Issue posts Dr Receivable / Cr Revenue / Cr Output VAT, and releases WIP by billed share. | [CODE] + tests |
| Credit note | A `project_invoices` row pointing at the invoice it credits; own journal (`JE-CN`). | [CODE] |
| Receipt | Recorded as pending; verified by a second person; verification posts Dr source / Cr Client Deposits. | [CODE] |
| Allocation to invoice | `JE-ALLOC`: Dr Client Deposits / Cr Receivable. | [CODE] |
| Invoice ≠ receipt | Holds. Revenue is never credited by a receipt. | [CODE] |
| Partial invoice, partial receipt | Supported. A receipt is one transfer that may be allocated across projects. | [CODE] |
| Overpayment, credit balance | Unallocated money stays in Client Deposits. | [CODE] |
| Invoice reversal | Void and credit note [NOT TRACED in detail; covered by the receivables tests]. |
| Receipt reversal | "Delete" is a reversal: journal reversed, row kept, logged. Refused while allocated to an invoice. | [CODE] |
| Project revenue event | Invoice issue. Correct event. Wrong amount field for margin (P0-8). | [CODE] |

Dev data: 83 of 85 client receipts are verified with no journal. They predate the ledger [DATA].

---

## 20. Reversal and correction matrix

| Transaction | Edit before approval | Edit after approval | Delete | Return for correction | Cancel | Reverse | Ledger effect | Project cost effect | Inventory effect | Payment effect |
|---|---|---|---|---|---|---|---|---|---|---|
| Purchase requisition | Yes (items rebuilt) | No | **Hard delete, any status, until a PO exists** | Reject | No | No | None | None | None | None |
| Purchase order | Yes | Only by approved amendment | **Hard delete while pending** | Yes | **No** | No | None | Commitments re-posted on amendment | None | None |
| GRN | — | No (refused) | Hard delete, Super Admin, heavily guarded, logged | No | No | **No** | None | None | None | None |
| Supplier bill | Yes | Only when returned, by preparer | **Hard delete while unverified and unpaid, not logged** | Yes | No | **No** | None | None | None | None |
| Payment | — | No | No | — | — | Yes | Payment, voucher and bill-payment journals reversed | **Cost line left standing (P0-2)** | None | Voided; cashbook credited |
| Stores receipt | — | No | No (405) | — | — | Yes | **None** | None | Stock out; GRN line reopened | None |
| Stores issue | — | No | No | — | — | Yes, if nothing was returned | **Dr AP / Cr WIP (P0-1)** | Negative actual | Stock back | None |
| Stores return | — | No | No | — | — | **No** (type not reversible) | — | — | — | — |
| Stock adjustment | Draft count editable | No | Draft count deletable | Reject | — | Movement reversible | **Count journal not reversed** | None | Quantity back | None |
| Financial requisition | Yes | Edit returns it to pending | [NOT TRACED] | Yes | Release of unused balance | Through its payments and surrenders | Commitment released | Commitment restated | None | Per payment |
| Surrender | Staged | No | No | Yes | — | Yes | Clearing journal reversed | Cost lines reversed [tests] | None | Requisition reopens |
| Client invoice | Draft | No | No | Yes | Void | Credit note | Reversing or credit journal | Revenue reduced; WIP release recalculated | None | Allocations must be cleared first |
| Client receipt | Until it reaches the ledger | No | Reversal only | — | — | Yes | Journal reversed | None | None | — |
| Journal | — | — | No | — | — | Yes, once, by permission | Flipped entry dated today | **None: the source document is not updated** | None | None |

**Destructive deletion flagged:** purchase requisition (even after approval), pending purchase order, unverified supplier bill, supplier master record. None of these models uses soft deletes, and only GRN deletion writes an audit row.

---

## 21. Cross-module status audit

| Status | Where | What it proves | What it does not prove |
|---|---|---|---|
| **Approved** | Purchase requisition | Someone with approval authority agreed the need | That an order exists or that money is reserved |
| Approved | Purchase order | Commercial terms agreed; commitment should exist | That the commitment was posted (queued); that anything arrived |
| Approved | Financial requisition | Money may be paid | That any was paid |
| Approved | Materials list | Project Officer and Production signed off; Stores may issue | Anything about purchasing |
| Approved | Payment voucher | A second person agreed | That it was posted or paid |
| **Received** | GRN | Dock acceptance was recorded | That stock is on the shelf (Stores completes later); that quality passed |
| Received | Financial requisition | The receiver confirmed the cash | That it was accounted for |
| Received | Client receipt list | Money was typed in | That it was verified or reached the ledger |
| **Paid** | Supplier bill | Balance is zero **after withholding tax** | That gross was paid; that the payment is not later reversed elsewhere |
| Paid | Payroll run | A payroll payment was posted | That it can be reversed |
| **Confirmed** | GRN line (`store_status`) | Stores put it into stock | That it was accrued |
| **Verified** | Supplier bill | Three-way match signed off and posted to Accounts Payable | That it was paid |
| Verified | Cost line | It counts toward project cost | That it reached the ledger (analytical lines never do) |
| Verified | Client receipt | A second person confirmed it and it posted | That it is allocated to an invoice |
| **Posted** | Journal | In the ledger | That it was not later reversed (status changes to `reversed`) |
| Posted | GRN line (`stock_status`) | Stock movement exists | That the stock is still there |
| Posted | Stores finance outbox | Cost line created | — |
| **Completed** | Enquiry task | Workflow step done | Any financial fact, except the budget task, whose completion is the budget approval |
| **Closed** | Financial requisition | Fully accounted for, derived | — |
| Closed | Project, financially | New manual costs and labour refused | **That producers are refused** (P1-3) |
| Closed | Accounting period | Postings refused | — |
| **Reconciled** | Surrender | Finance accepted it and posted the clearing entry | — |
| Reconciled | Payment | Matched to a bank statement line; reversal refused | — |

Misleading in practice: "Received" (three meanings), "Approved" (five documents), "Posted" on a GRN line versus "Posted" on a journal, and "Closed" on a project that still accepts Stores and procurement costs.

---

## 22. Duplicate data and sources of truth

| Field | Authoritative | Copy or projection | Risk |
|---|---|---|---|
| Project on a purchase | `requisitions.project_id` | `requisition_items.project_enquiry_id` (historically held project ids), `requisitions.job_number` (a display string), `payments.project_id`, `bills.project_id` (direct only) | Producers resolve correctly. Journals and payments read a non-existent `requisitions.project_enquiry_id` (P1-9). **Dangerous independent value.** |
| Supplier | `suppliers` | `purchase_orders.supplier_id`, copied to the bill; free-text `payee_name` on payments and cost lines | Copy at creation. Supplier can be hard-deleted (P1-5). |
| Order value | Sum of `purchase_order_items` | `purchase_order_items.total`, order total | Projection. |
| Unit price | `purchase_order_items.unit_price` | `goods_receipt_note_items.receipt_unit_cost` and `unit_price`, `inventory_logs.receipt_unit_cost` and `unit_price`, `library_materials.unit_cost` | **Manual duplicate.** Stores can retype it (P1-8). |
| Quantity received | `goods_receipt_note_items.received_quantity` (inspection overrides) | `stock_quantity`, `inventory_logs.quantity` | Guarded equal at posting. |
| VAT and WHT | `bills` | Recomputed on cost lines at verification | Independent per route by design. Absent on orders and receipts (P0-3). |
| Expense type | `expense_codes` | Requisition type default code, material category mapping, free-text `payments.account` (legacy) | Legacy text kept for mapping. |
| Material | `library_materials` | `element_materials.library_material_id`, `purchase_order_items.material_id` (nullable), free-text descriptions | Null material is the non-stock path (P0-4). |
| Payment status | `bills.status`, derived by `updatePaymentStatus()` | `paid_amount`, `balance` | Projection, kept by model events. |
| GRN status | Item `stock_status` and `store_status`; header `store_status` | Two status fields per line, three writers | Known dual-status invariant; header roll-up now present. |
| Cost status | `cost_lines.status` | `settled_by_bill_id`, `settled_by_payment_id`, `posted_at`, outbox status, `inventory_logs.finance_sync_status` | Several flags describing one fact. `settled_by_payment_id` is written by nothing. |
| Budget | `task_budget_data` JSON | planned cost lines | Projection through a queued listener (P0-7). |
| Client | `clients` | Free text on legacy payments | Low. |

Unnecessary re-entry: the receipt price at Stores; the project on a direct bill (nothing offers the job's open commitments); supplier name on surrender receipts.

---

## 23. Permissions and segregation

### 23.1 Roles per workflow

| Workflow | Creator | Verifier / approver | Finance processor | Reverser | Self-approval blocked on the server |
|---|---|---|---|---|---|
| Purchase requisition | Any signed-in user | `approveRequisition` gate | — | — | Tested (`RequisitionApprovalSegregationTest`) |
| Purchase order | **Any signed-in user** | `approveOrder`, `approveOrderSenior` | — | — | Tested |
| GRN | **Any signed-in user** | Stores (`stores.manage`) completes the line | — | Super Admin delete | Dock and Stores are separate steps, not separate people by rule |
| Supplier bill | **Any signed-in user** | `finance.payables.verify`, never the preparer | Payer needs `finance.petty_cash.create_disbursement` | None exists | Yes |
| Bill payment | Payer | The match is the approval | — | `finance.payments.reverse` | No rule that the payer differs from the verifier |
| Stock movement | `stores.manage` | — | Outbox retry | `stores.movement.reverse` or `stores.manage` | No second person for a write-off |
| Stock count | `stores.manage` | `stores.review` | — | — | Yes, separate permissions |
| Financial requisition | Requester | Responsible verifier, approver | Payer is never the approver (75R-C) | Reversal permission | Yes |
| Cost capture | Reporter | Verifier, never the reporter | Voucher | Verifier | Yes, with recorded override |
| Payment voucher | Requester | Approver, senior approver, poster | — | Payment reversal | Yes, at every step |
| Client receipt | Recorder | Second person | — | Reversal with reason | Yes |
| Labour actual | Recorder | Project Officer, then Finance | — | Correction | Yes |
| Journal | System only | — | — | `finance.journals.reverse` | Not applicable: no manual journals exist |

### 23.2 Server-side gaps

Every route in `ProcurementStores/Routes/api.php` sits behind `auth:sanctum` and `active` only. Authorisation is inside each controller action. Actions with no permission check at all [CODE]:

| Action | Effect | Priority |
|---|---|---|
| `SupplierController` store, update, **destroy** | Create, change or hard-delete a payee | P1-5 |
| `GoodsReceiptNoteController::store` | Records a receipt; fires the accrual, which posts to the ledger | P1-6 |
| `PurchaseOrderController::store`, `link`, `sendEmail`; `update` while pending | Create or change a draft order; email a supplier | P1-6 |
| `RequisitionController::store`, `update`, `submitForApproval` | Raise a purchase request | Probably intended; confirm |
| `BillController::store` | Record a supplier bill (it still needs a different person to verify it) | Confirm |
| `BoardController` `claimWorkflowTask`, `returnOffcut`, `saveReconciliation`, `calculateVariance` | Board workflow writes | P2 |
| Read actions on bills, orders, GRNs, suppliers, inventory logs | Financial data visible to any signed-in user | P2-10 |

Whether the frontend hides these buttons was not tested per role. The server does not enforce them, which is the finding.

Known and unchanged: permission migrations grant to roles that may not exist. The test run printed that warning for Accounts, Admin, Manager, Stores, Costing, Production and Super Admin on a fresh database (expected there; it must be checked on the target).

---

## 24. Audit trail

| Event | Who | What / amount | When | Why | Before → after | Related document | Project | Accounting impact |
|---|---|---|---|---|---|---|---|---|
| Cost line created | Submitter | Yes | Yes | Description, unbudgeted reason | Append-only | Source type, id, ref | Yes | Journal link |
| Cost line verified, queried, rejected, reversed | Yes | Yes | Yes | Note required | Status + revisions in `capture_meta` | Yes | Yes | Reversing journal |
| Journal | `created_by` | Yes | Yes | Description | Immutable; reversal linked | Source | On lines | Itself |
| Payment | `created_by`, `voided_by` | Yes | Yes | Void reason | Status | Requisition, voucher, bill | Yes | Journal by source |
| Supplier bill verify, return, correct | Yes (governance log) | Yes | Yes | Notes | Before and after on correction | Order | Through the order | Journal |
| Supplier bill delete | **No record** | — | — | — | — | — | — | — |
| Purchase requisition or order delete | **No record** | — | — | — | — | — | — | — |
| Supplier create, edit, delete | **No record found** | — | — | — | — | — | — | — |
| GRN delete | Governance log | Yes | Yes | No reason captured | — | — | — | — |
| Stock movement | `user_id` | Quantity, frozen cost | Yes | Notes | `balance_after` | GRN, issue, reversal links | Yes | Outbox row |
| Stock write-off (damage) | Yes | Yes | Yes | Notes required | Yes | — | Not required | **None** |
| Accrual released by an issue | **System, no actor** | Yes | `verified_at` overwritten | `query_note` | Status only | Issue ref in the note | Yes | None |
| Commitment released | **System, no actor** | Yes | `verified_at` overwritten | `query_note` | Status only | — | Yes | None |
| Requisition payment reversal, receipt confirmation, release | Governance log | Yes | Yes | Reason | Before state kept | Payment | Yes | Journals |
| Client receipt verify, correct, reverse | Governance log | Yes | Yes | Reason | Old and new amount | Receipt | Yes | Journal |

Weak spots: hard deletes with no trace; `releaseCommitment` and `releaseAccrual` reuse `verified_at` as the release time and record no actor; a journal reversed from the ledger screen leaves its source document saying nothing happened.

---

## 25. UI and workflow clarity

Problems recorded. No redesign proposed here.

| # | Problem | Where |
|---|---|---|
| 1 | **A project cannot be read in the Projects module.** Budget, commitment, receipt, issue, payment and margin are in Finance → Project Costs. Projects links there only from the materials task. | Projects |
| 2 | **Two documents are both called "Requisition".** Purchase requisition (`/procurement/requisitions`) and financial requisition (`/finance/petty-cash/requisitions`). Finance navigation also lists `/finance/purchasing/requisitions`. | Procurement, Finance |
| 3 | **Supplier invoices have two homes.** Procurement → Billing (index, create, show) and Finance → Payables → Bills (list, detail). Same rows, different screens and wording ("Billing", "Bills", "Supplier invoices"). | Procurement, Finance |
| 4 | **Goods receipt is split over three screens** with no shared stage indicator: GRN create (Procurement), Goods Receipt Confirmation and Receipt Inspections (Stores). | Procurement, Stores |
| 5 | **A dead-end screen.** `/logistics/transport-costs`, titled "Transport Costs", shows the Drivers list. | Logistics |
| 6 | **Technical language in messages a storekeeper or requester sees:** "three-way match", "accrual", "cost ledger", "cost line CL-…", "posting", "outbox". | Bill, GRN, Stores errors |
| 7 | **"Spent" hides its make-up.** It is actual plus received-not-invoiced. A user comparing it with Stores issues or with payments must reconstruct it. | Cost account |
| 8 | **Margin has no visible basis.** The API says provisional and direct and names the cost basis; whether the screen shows the VAT basis or the switch between bases is not evident from its labels. | Cost account |
| 9 | **A failed Stores → Finance posting is visible only on the finance sync exceptions list.** The storekeeper's issue succeeds; nobody is told the job was not charged. | Stores, Finance |
| 10 | **Where is the accounting effect?** From a GRN, an issue or a requisition there is no link to the journal or the cost line it produced. From a cost line the journal number is shown. | All four |
| 11 | **Who owns the next action** after dock acceptance, after a bill is recorded and after a surrender is submitted is implied by which queue the item appears in, not stated on the document. | Procurement, Finance |
| 12 | **Users must combine modules mentally** to answer "is this purchase finished?": order (Procurement), receipt (Procurement + Stores), bill (Procurement or Finance), payment (Finance), cost (Finance). The order workflow endpoint computes this; it is shown on the order page only. | All |
| 13 | **Stale copied data on screen.** The requisition's job number is a display string that drifts from the enquiry's job number. | Procurement |
| 14 | **Status words collide** (§21). | All |

Not assessed: click counts, layout, tables versus cards. Those need the screens exercised by role, which this audit did not do.

---

## 26. Bugs

### P0

**P0-1 — Stock issue reversal debits Accounts Payable.** [PROBE]
`StoresCostProducer::postStockIssueReversal` posts a negative actual with `source_ref = 'stock-issue-reversal'`. `JournalPostingService::settlementAccountFor` recognises only `'stock-issue'` and `'stock-return'` as Inventory movements (`JournalPostingService.php:652`), so the reversal falls to the final fallback, Accounts Payable.
Observed: issue `Dr 1211 WIP 1,000 / Cr 1200 Inventory 1,000`; reversal `Dr 2100 Accounts Payable 1,000 / Cr 1211 WIP 1,000`.
Effect: Inventory understated and Accounts Payable understated by the value of every reversed issue. The existing test asserts only that a journal exists.

**P0-2 — Finance payment reversal leaves the project cost.** [PROBE]
`PaymentController::reverse` calls `PaymentReversalService::reverse`, which reverses journals whose source is the payment, its voucher or its bill payments. The cost line of a directly costed payment has its own journal (`JE-CL-…`, source `CostLine`). Only `PettyCashService::voidDisbursement` dispatches the event that reverses it.
Observed after `POST /api/finance/payments/1/reverse` (HTTP 200): payment `voided`, float back to 500,000, cost line still `verified`, its journal still `posted` (Dr WIP 4,500 / Cr Petty Cash Float 4,500), project actual still 4,500.
Effect: job overstated; ledger float understated against the cashbook.

**P0-3 — The receipt accrual is never reconciled to the supplier invoice.** [DATA][CODE]
See §6. Any difference between accepted quantity × order price and the bill's net amount (VAT included in the order price, a price change, a discount, part billing never completed) stays in Accrued Expenses and in Inventory. Rehearsal: 1,379.32 on one bill.
Root cause is partly policy: nothing records whether an order price includes VAT.

**P0-4 — Non-stock purchase lines become Inventory and never cost.** [CODE][DATA]
`postGoodsReceipt` accrues every accepted line. `resolveAccountsForCostLine` debits Inventory for every accrual. A line with no library material is `not_stocked`, is never issued, and nothing else turns it into an actual. Rehearsal: `CL-0003885`, 3,000, `not_stocked`, Dr Inventory.
Effect: services and custom items bought on an order overstate Inventory permanently, never reach WIP, expense or project margin, and stay "accrued" on the job for ever. Dev and rehearsal hold few such rows; the control is absent whatever the volume.

**P0-5 — Stock received outside a GRN reaches no ledger.** [CODE][DATA]
`InventoryService::adjustStock` posts no journal for `check_in`. Only GRN acceptance debits Inventory. Every later issue credits Inventory.
Data: dev 13 of 15 receipts have no GRN; rehearsal 13 of 14. Dev ledger Inventory −400 against Stores 305,310.
Effect: Inventory in the ledger drifts negative as unrecorded stock is issued. The opening-inventory count closes this once; nothing closes it afterwards.

**P0-6 — Stock leaves or moves with no accounting.** [CODE]
(a) Damage (`defective`): no event, no journal, no cost. (b) Issue with neither project nor reference: `StockIssued` is not dispatched. (c) Receipt reversal: stock removed, accrual journal untouched. (d) Reversal of an adjustment movement: quantity restored, the count's journal untouched.
Data: dev has 9 issues with no project and no reference, 0 damage rows.

**P0-7 — Finance listeners depend on a queue worker that production evidence says is absent.** [DATA][CODE]
Queued: `RecordPurchaseOrderCommitments`, `RecordGoodsReceiptAccruals`, `RecordPettyCashCommitment`, `RecordPettyCashCost`, `ReleasePettyCashCommitment`, `ReversePettyCashCost`, `ProjectBudgetLines`, `SyncBudgetWithMaterialsList`.
Data: `woodnork_erpsystem.jobs` holds 1,088 unprocessed jobs, created 2026-01-06 to 2026-07-27, `failed_jobs` empty. That is the signature of an asynchronous driver with no worker. Report 48 reached the same conclusion from commit history and left it unproven; this is direct evidence from the source copy, not from the live target.
Effect if the target matches: no commitment, no accrual (so no Inventory debit and no Accrued Expenses), no direct petty-cash cost, no void reversal, no budget projection after the first, all silent. Tests cannot see it (`QUEUE_CONNECTION=sync`).

**P0-8 — Margin mixes VAT-inclusive revenue with VAT-exclusive cost.** [CODE]
`marginAgainstJournals` and `portfolioMargin` sum `project_invoices.total_amount`. `InvoicePricer` sets `total_amount = subtotal + tax_amount`. Cost lines are net of recoverable VAT.
Effect: margin and margin percent overstated by the output VAT on every billed job. `WorkInProgressReleaseService::billedFraction` divides the same VAT-inclusive total by `quote_amount`; whether that is like for like depends on whether quote amounts include VAT [NOT TRACED].

**P0-9 — "Company paid" cost capture moves money in the ledger with no payment.** [CODE]
`StoreCostLineRequest` stores `payment_source_id` in the line's details. On verification `postCostLine` credits that paying account's ledger account. No `Payment` is created, the petty-cash balance and cashbook are not debited, and there is nothing for bank reconciliation to match.
Effect: ledger cash and the custody records disagree by every such cost.

### P1

| ID | Finding | Evidence |
|---|---|---|
| P1-1 | **Accrual relief is keyed on project + material, not on the delivery.** A part issue retires the whole accrual (documented as deliberate); stock received for project A and issued to project B leaves A's accrual standing. `StoresCostProducer::relieveAccrualsFor`. | [CODE] |
| P1-2 | **No purchase order close or cancel.** The remaining commitment of a short-delivered or abandoned order is never released. No route exists. | [CODE] |
| P1-3 | **A financially closed project still receives costs.** Only `collect()`, labour, transfer and allocation check `financial_closure_status`. `postFromSource()` does not, so Stores issues, receipts, surrenders and direct bills post to a closed job. | [CODE] |
| P1-4 | **No supplier credit note and no bill reversal.** The delete refusal tells the user to "reverse or credit it instead"; neither action exists. | [CODE] |
| P1-5 | **Supplier master is unguarded.** Create, update and hard delete need only a login. | [CODE] |
| P1-6 | **GRN creation and draft order creation or editing need only a login.** A GRN posts an accrual to the ledger. | [CODE] |
| P1-7 | **PR, PO, GRN, GRN batch and bill numbers are "last + 1" with no lock.** Two concurrent creates take the same number. The shared `DocumentNumber::next` (locked sequence) is used for payments only. | [CODE] |
| P1-8 | **Stock is valued by Stores at a figure the ledger never saw.** Stores may enter a receipt cost different from the order price; issues fall back to a default price or to the budget rate. Inventory is credited at values it was never debited at. | [CODE] |
| P1-9 | **PO-backed bills and payments carry no enquiry id.** `postSupplierInvoice`, `postSupplierPayment` and `SupplierPaymentService` read `requisition->project_enquiry_id`; `requisitions` has `project_id` and `job_number` only. Project "paid out" (`CostAccountService::cashMovements`) therefore excludes every supplier payment, and a batch payment across jobs is tagged with the first bill's project. | [CODE][DATA: column list] |
| P1-10 | **Guessed accounts.** Surrender expense with an unmapped code debits the first expense account by code; an advance from a paying account with no ledger account credits the default bank. | [CODE] |
| P1-11 | **`ReversePettyCashCost` crashes when the void has no user.** The error-logging branch reads `$line->id` before `$line` exists (`ReversePettyCashCost.php`, the no-actor branch). The job throws and the cost is not reversed. | [CODE] |
| P1-12 | **Margin changes basis without saying so** and drops labour on the released basis (§16). | [CODE] |
| P1-13 | **The first bill on an order marks every accrual on that order as settled**, including receipts not yet billed. `markGrnAccrualsSettledByBill`. | [CODE] |
| P1-14 | **Materials bought with a financial requisition and then taken into Stores are costed twice** (surrender, then issue). No link, no warning. | [CODE] |

### P2 and P3

Listed in §29. Not repeated here.

### Searched for and not found

| Looked for | Result |
|---|---|
| Unbalanced journal | None in data; refused by the funnel |
| Journal without lines or without a source | None in data |
| Period bypass | None found: cost line, journal and reversal all check the period |
| Second writer of cost lines or journals | None in live code |
| Duplicate posting on retry | Idempotent on source key (cost lines) and entry number (journals) |
| Negative stock | None in data; floor enforced under row lock |
| Race on stock, payment, bill payment, receipt allocation, GRN confirmation | Row locks present in each |
| Orphan cost line (no source) | None in data |
| N+1 queries | Not measured in this audit |

---

## 27. Data integrity findings

Read-only. Queries: `76-evidence/integrity-queries.sql`. Raw output: `76-evidence/integrity-dev-db.txt`, `76-evidence/integrity-rehearsal-db.txt`.

**Caveat.** The dev database had its Finance tables reset (7 cost lines, 6 journals) while keeping 1,554 legacy payments and 76 stock movements. The rehearsal database is a small scripted data set. Neither is production. Counts show what the controls allow, not the size of the problem on live data.

| # | Check | Dev `db` | Rehearsal | Reading |
|---|---|---|---|---|
| 1 | Payments total / active | 1,554 / 1,554 | 14 / 13 | — |
| 2 | Active payments with no source link | 1,554 (KES 2,234,861.94) | 9 (45,000) | Legacy direct disbursements |
| 3 | Active payments with no journal and no cost line | 1,554 | 2 (83,610.65) | Dev: ledger reset. Rehearsal: false positives — one payroll payment and one voucher payment, each with a journal sourced to its run or voucher |
| 6 | Active payments with no paying account | 1,554 | 0 | Legacy rows predate paying accounts |
| 8 | Journals without a source | 0 | 0 | Pass |
| 9 | Unbalanced journals | 0 | 0 | Pass |
| 10 | Journals with no lines | 0 | 0 | Pass |
| 11 | Cost lines without a source | 0 | 0 | Pass |
| 12 | Verified actual or accrued lines never posted | 0 | 1 (900, labour) | By design |
| 13 | Reversed cost line, journal still posted | 1 | 0 | The released accrual; by design |
| 16 | GRNs without a PO | 0 | 0 | Pass |
| 17 | Accepted GRN lines with no material | 0 | 1 | P0-4 |
| 18 | PO lines with no material | 0 | 2 (13,000) | P0-4 |
| 20 | Accepted GRN lines with no accrual | 0 | 0 | Pass where the queue ran |
| 21 | Approved POs with no commitment | 0 | 0 | Same |
| 22 | GRN stock quantity ≠ received quantity | 0 | 0 | Pass |
| 23 | GRN receipt cost ≠ order price | 0 | 0 | Pass on this data |
| 25 | Bills paid with a balance | none | 0 | Pass |
| 26 | Stock receipts with / without a GRN | 2 / 13 | 1 / 13 | **P0-5** |
| 28 | Issues with no project and no reference | 9 | 9 | P0-6 |
| 30 | Issues frozen at zero unit cost | 53 (109 units) | column absent | DATA VERIFICATION |
| 31 | Project issues with no cost line | 44 | 43 | History predating the outbox; DATA VERIFICATION on live data |
| 33 | Stores finance outbox | 3 posted, 1 failed | 1 posted | Failed row needs a manual retry (P2-4) |
| 34 | Damage movements | 0 | 0 | — |
| 35 | Negative stock | 0 | 0 | Pass |
| 36 | Stock on hand with no valuation | 17 items (112 units) | 0 | DATA VERIFICATION |
| 38 | Valuation with zero quantity | 0 | 4 | Harmless |
| 39 | Stock balance ≠ last movement's balance | 5 | 13 | **DATA VERIFICATION** (P2-11) |
| 40 / 41 | Stores valuation vs ledger Inventory | 305,310 vs −400 | 10,750 vs 12,250 | **P0-5, P0-3, P0-4** |
| 44 | Projects financially closed | 0 | 0 | Closure untested by data |
| 46 | Verified client receipts with no journal | 83 of 85 | 0 | Pre-ledger history |
| 47 | Issued invoices with no journal | 0 | 0 | Pass |
| 48 | Projects with actual > 0 and no plan | 2 | 0 | Unbudgeted spend |
| 50 | Queued jobs waiting | 0 | 20 (notifications) | Source copy: **1,088** (P0-7) |

Not produced, because the tables are empty or the event has never occurred in either database: closed documents with outstanding balances, reversed records still counted in reports.

---

## 28. Master implementation matrix

| Area | Workflow | Source of truth | Current connection | Accounting effect | Project cost effect | Status | Bug / gap | Risk | Recommended action |
|---|---|---|---|---|---|---|---|---|---|
| Cost ledger | Single writer, stages | `cost_lines` | One service | — | All stages | IMPLEMENTED & VERIFIED | — | LOW | Keep |
| Ledger | Single funnel | `journal_entries` | One service | Balanced, period-checked | — | IMPLEMENTED & VERIFIED | Dead `writeEntry`; three deprecated payment engines | LOW | Remove dead code later |
| Infrastructure | Queued finance listeners | — | 8 listeners `ShouldQueue` | Accrual journal depends on it | Commitment, accrual, direct cost depend on it | DATA VERIFICATION REQUIRED | P0-7 | CRITICAL | Verify target queue; make finance postings worker-independent |
| Procurement | Requisition → PO | `requisitions`, `purchase_orders` | Direct link | None | Commitment | IMPLEMENTED & VERIFIED | Unguarded create; hard delete; numbering | HIGH | P1-6, P1-7, P2-8 |
| Procurement | PO amendment | `purchase_order_amendments` | Re-posts commitments | None | Commitment restated | IMPLEMENTED & VERIFIED | — | LOW | Keep |
| Procurement | PO close / cancel | — | None | — | Commitment never released | MISSING | P1-2 | HIGH | Add after WNG rule |
| Procurement → Finance | GRN accrual | `goods_receipt_note_items` | Queued event | Dr Inventory / Cr Accrued | accrued | PARTIAL | P0-3, P0-4, P1-1 | CRITICAL | Policy first, then true-up and non-stock treatment |
| Procurement → Stores | GRN → stock | GRN line → `inventory_logs` | One posting, locked | None from the movement | None | IMPLEMENTED & VERIFIED | Price can be retyped | MEDIUM | P1-8 |
| Stores | Receipt without GRN | `inventory_logs` | None to Finance | **None** | None | MISSING | P0-5 | CRITICAL | Decide the source account per receipt reason; post |
| Stores → Projects | Issue | `inventory_logs` → outbox | Synchronous outbox | Dr WIP / Cr Inventory | actual | IMPLEMENTED & VERIFIED | Fallback valuation | MEDIUM | P1-8 |
| Stores → Projects | Return | `inventory_logs` | Outbox | Dr Inventory / Cr WIP | negative actual | IMPLEMENTED & VERIFIED | — | LOW | Keep |
| Stores → Finance | Issue reversal | `inventory_logs` | Direct call | **Dr AP / Cr WIP** | negative actual | BUG | P0-1 | CRITICAL | Fix settlement account; add assertion |
| Stores → Finance | Damage, non-project issue, receipt and adjustment reversal | `inventory_logs` | None | **None** | None | MISSING | P0-6 | HIGH | Policy, then post |
| Stores → Finance | Stock count | `stock_counts` | Direct | Inventory vs adjustments / opening equity | None | IMPLEMENTED BUT NEEDS CLARITY | Zero-cost items skipped; current-cost valuation | MEDIUM | P2-6 |
| Stores ↔ Finance | Valuation agreement | Two valuations | Report only | — | — | PARTIAL | No reconciling control | HIGH | Reconciliation as a period-end gate |
| Payables | Supplier bill, PO-backed | `bills` | Three-way match | Dr Accrued (+VAT, −WHT) / Cr AP | None | IMPLEMENTED & VERIFIED | P0-3, P1-13 | CRITICAL | With accrual true-up |
| Payables | Direct bill | `bills` | Verify | Dr expense / Cr AP | actual | IMPLEMENTED & VERIFIED | — | LOW | Keep |
| Payables | Credit note, bill reversal, supplier return | — | None | — | — | MISSING | P1-4 | HIGH | Design after WNG process |
| Payables | Bill payment | `payments` + `bill_payments` | Settlement engine | Dr AP / Cr source | None | IMPLEMENTED & VERIFIED | P1-9 | MEDIUM | Carry enquiry id |
| Payments | Reversal | `PaymentReversalService` | Two entry points behave differently | Payment journals reversed | **Cost left** | BUG | P0-2 | CRITICAL | One reversal path that also reverses a payment-sourced cost |
| Payments | Direct petty-cash payment as cost | `payments` | Queued | Dr expense / Cr float | actual at payment | POLICY DECISION REQUIRED | Payment = expense | MEDIUM | WNG to keep or retire |
| Cost Collector | Manual capture + verification | `cost_lines` | Direct | Dr expense / Cr AP | actual | IMPLEMENTED & VERIFIED | — | LOW | Keep |
| Cost Collector | Company-paid capture | `cost_lines` | No payment | Dr expense / Cr cash | actual | BUG | P0-9 | HIGH | Create a `Payment` through the engine |
| Vouchers | Payment voucher | `spend_vouchers` | Settles liabilities | Dr liability / Cr source | None | IMPLEMENTED & VERIFIED | — | LOW | Keep |
| Requisitions | Request → payment → surrender → closure | `petty_cash_requisitions` and children | 75R series | Advance, then clearing | Commitment, then actual once | IMPLEMENTED & VERIFIED | P1-10, P1-14 | MEDIUM | Remove fallbacks; add a Stores link or rule |
| Projects | Budget projection | planned lines | Queued | None | planned | IMPLEMENTED BUT NEEDS CLARITY | Queue | HIGH | With P0-7 |
| Projects | Financial closure | `financial_closure_status` | Checked on manual paths only | — | Producers bypass | PARTIAL | P1-3 | HIGH | Guard `postFromSource`; define what a late cost does |
| Projects | Cost account, margin | `CostAccountService` | Reads cost lines, invoices, journals | — | — | BUG | P0-8, P1-12 | HIGH | Net revenue; one stated basis |
| Projects | Financial visibility inside Projects | — | Link from materials task only | — | — | PARTIAL | P2-1 | MEDIUM | 76D |
| Revenue | Invoice, credit note, receipt, allocation | `project_invoices`, `enquiry_payments` | W1 | Receivable, revenue, VAT, deposits | Revenue | IMPLEMENTED & VERIFIED | Quote VAT basis unverified | MEDIUM | DATA VERIFICATION |
| Revenue | WIP release | `WorkInProgressReleaseService` | On invoice issue | Dr COS / Cr WIP | — | POLICY DECISION REQUIRED | WIP policy sign-off pending; fraction basis | HIGH | WNG sign-off |
| Labour | Labour actual → cost | `project_labour_actuals` | Analytical | Payroll only | actual | IMPLEMENTED BUT NEEDS CLARITY | No ledger job cost; no payroll tie-out | MEDIUM | POLICY DECISION |
| Logistics | Fleet, fuel, transport | Logistics tables | **None** | None | None | MISSING | §18 | MEDIUM | W8, after decision |
| Security | Server-side authorisation in Procurement / Stores | Controllers | In-action checks | — | — | PARTIAL | P1-5, P1-6, P2-10 | HIGH | Permission per write action |
| Audit | Deletes | — | Hard delete | — | — | PARTIAL | P2-8 | MEDIUM | Soft delete or refuse; log |

---

## 29. Priorities

### P0 — could cause wrong money, ledger, stock or project cost (9)

| ID | Finding |
|---|---|
| P0-1 | Stock issue reversal debits Accounts Payable instead of Inventory |
| P0-2 | Finance payment reversal leaves the project cost and its journal |
| P0-3 | Receipt accrual never reconciled to the supplier invoice (VAT and price differences stranded) |
| P0-4 | Non-stock purchase lines debit Inventory and never become cost |
| P0-5 | Stock received outside a GRN reaches no ledger |
| P0-6 | Damage, non-project issues, receipt and adjustment reversals have no accounting |
| P0-7 | Finance listeners depend on a queue worker that production evidence says is absent |
| P0-8 | Margin mixes VAT-inclusive revenue with VAT-exclusive cost |
| P0-9 | Company-paid cost capture credits cash with no payment document |

### P1 — could allow duplicate or incorrect approval, payment or posting (14)

P1-1 accrual relief by material · P1-2 no order close or cancel · P1-3 closed project accepts producer costs · P1-4 no supplier credit note or bill reversal · P1-5 supplier master unguarded · P1-6 GRN and draft order creation unguarded · P1-7 unlocked document numbering · P1-8 Stores valuation differs from ledger valuation · P1-9 no enquiry id on PO-backed bills and payments · P1-10 guessed accounts on surrender and advance · P1-11 void-reversal listener crash · P1-12 margin basis switch · P1-13 first bill settles every accrual on the order · P1-14 requisition purchase plus Stores issue costed twice

### P2 — workflow or data clarity problems that cause operational mistakes (12)

| ID | Finding |
|---|---|
| P2-1 | A project's financial position is not readable inside Projects |
| P2-2 | Two "Requisition" documents and two supplier-invoice homes |
| P2-3 | Status words mean different things across modules (§21) |
| P2-4 | A failed Stores → Finance posting waits for a manual retry; the sweep command handles `pending` only |
| P2-5 | Cost-line idempotency ignores status: once a producer line is reversed, the same source key can never post again |
| P2-6 | Stock count variance is priced at today's catalogue cost; items with no cost are skipped silently |
| P2-7 | "Transport Costs" shows Drivers; fleet costs that are captured go nowhere |
| P2-8 | Hard deletes with no audit record: purchase requisition, pending order, unverified bill, supplier |
| P2-9 | Duplicate controls do not cross routes (surrender receipt, supplier bill, cost capture, payment reference) |
| P2-10 | Read endpoints for bills, orders, receipts, suppliers and stock movements are open to any signed-in user |
| P2-11 | Stock balance differs from the last movement's balance on 5 (dev) and 13 (rehearsal) materials — DATA VERIFICATION |
| P2-12 | Quote margin (planned, in Projects) and cost-account margin (actual, in Finance) are two unrelated figures with the same name |

### P3 — usability and reporting improvements (6)

| ID | Finding |
|---|---|
| P3-1 | `getAvailablePurchaseOrders` loads every approved order and filters in PHP |
| P3-2 | Float arithmetic in batch bill-payment allocation and receipt allocation; the rest of Finance uses `bcmath` |
| P3-3 | Accounting vocabulary in user-facing messages |
| P3-4 | Bill creation returns HTTP 500 for any exception, and creates then deletes the bill outside a transaction when the cap is exceeded |
| P3-5 | Four-digit yearly sequences in PR, PO, GRN and bill numbers |
| P3-6 | Two payment creators and three live payment-journal engines beside one unused replacement (§13) |

---

## 30. Recommended implementation sequence

The brief's suggested phases fit, with two changes: the queue question moves to the very front because it decides whether half the chain runs at all, and WNG decisions are separated from code so that 76A is not blocked by them.

| Phase | Scope | Depends on WNG | Findings |
|---|---|---|---|
| **76A-0** | Verify the target's queue driver (the read-only check in Report 48 §9). Decide: synchronous finance postings with a visible failure record, as Stores already does, or a supervised worker. | Hosting decision | P0-7 |
| **76A-1** | Code-only integrity fixes, each with a test that asserts the accounts and the project figure: issue-reversal account; payment reversal that also reverses a payment-sourced cost, from every entry point; net revenue in margin; company-paid capture through the settlement engine; listener crash. | No | P0-1, P0-2, P0-8, P0-9, P1-11 |
| **76A-2** | Decisions paper for WNG, then implementation: order price VAT basis and accrual true-up; non-stock purchase treatment; receipts without a GRN; damage and non-project issues; reversal of receipts and adjustments. | **Yes** (§32 items 1–5) | P0-3, P0-4, P0-5, P0-6 |
| **76B** | Procurement ↔ Stores ↔ Finance: order close and cancel; supplier credit note and bill reversal; accrual relief by delivery; per-bill accrual settlement; enquiry id on bills and payments; one valuation or an enforced reconciliation; locked numbering. | Partly | P1-1, P1-2, P1-4, P1-7, P1-8, P1-9, P1-13 |
| **76C** | Stores ↔ Projects: requisition purchases taken into stock; closed-project guard on producers; stock count valuation; outbox retry sweep. | Partly | P1-3, P1-14, P2-4, P2-5, P2-6 |
| **76D** | Project financial visibility: one project financial statement with every figure named and sourced; one margin basis, labelled direct and provisional; paid and unpaid per project. | No | P1-12, P2-1, P2-12 |
| **76E** | Workflow, status and navigation clarity across the four modules. | No | P2-2, P2-3, P2-7, P3-3 |
| **76F** | Permissions, audit and performance residue. | Role decisions | P1-5, P1-6, P1-10, P2-8, P2-9, P2-10, P2-11, P3-1, P3-2, P3-4, P3-5, P3-6 |

Labour and logistics stay with W7 and W8 and their own decisions.

---

## 31. Tests and evidence

**Environment.** Isolated database `db_report76_test`, created for this audit with a copied PHPUnit config, both removed afterwards. Runs were serial; no other test process was running. `QUEUE_CONNECTION=sync` in tests, so queued listeners run inline there.

**Suites run:** `tests/Feature/Finance`, `Procurement`, `ProcurementStores`, `Stores`, `CostCollector`, `PettyCash`.

| Run | Database | Result |
|---|---|---|
| Six suites, one serial run (136 test classes passed, 2 failed) | `db_report76_test` | **1,416 passed, 10 failed**, 10,282 assertions, 808 s |
| The two failing classes, re-run | `db_scratch_test` | **10 passed** (plus 4 from a small suite run first to migrate the schema) |

All 10 failures were one cause, unrelated to the product: `W7LabourConcurrencyTest` (5 tests) and `RequisitionReceiverDisbursementConcurrencyTest` (5 tests) commit data from forked processes and refuse to run on any database other than `db_test` or `db_scratch_test`. On an allowed database all 10 pass. **Net: 1,426 of 1,426 pass. No test exposed a defect.**

That result and the P0 list are both true. The suites are green because nothing in them asserts the behaviours listed under "Coverage gaps" below.

**Probes.** A temporary test, `tests/Feature/CostCollector/Report76ProbeTest.php`, observed two behaviours and asserted nothing about correctness. It passed 2 of 2 and was deleted. Output:

```
PROBE-A issue journal:    debit 1211 Project WIP – Direct Materials 1000.00 | credit 1200 Raw-material Inventory 1000.00
PROBE-A reversal journal: debit 2100 Accounts Payable 1000.00 | credit 1211 Project WIP – Direct Materials 1000.00

PROBE-B before: payment=active cost_line=verified journal= debit 1211 Project WIP – Direct Materials 4500.00 | credit 1030 Petty Cash Float 4500.00
PROBE-B after:  http=200 uri=/api/finance/payments/1/reverse payment=voided cost_line=verified cost_journal=posted
                reversing_entries=0 project_verified_actual=4500.00 float_balance=500000.00
```

**Coverage gaps the suites have**, each matching a finding: no test asserts which accounts a stock-issue reversal posts to; none reverses a directly costed payment through the Finance endpoint; none posts a bill whose net differs from its accrual; none receives a non-stock order line and follows it to cost; none receives stock without a GRN and checks the ledger.

**Not run:** `tests/Feature/Projects`, `Hr`, `Unit`, and the frontend suites. Not exercised: the UI by role, the live target, the production queue.

---

## 32. Remaining WNG decisions

No decision below was made in this report.

| # | Decision | Blocks |
|---|---|---|
| 1 | Are purchase order prices VAT-exclusive or VAT-inclusive? Should the order say which? | P0-3 |
| 2 | When the supplier invoice differs from the receipt value (price, discount, VAT), where does the difference go: adjust stock value, a purchase price variance account, or the job? | P0-3 |
| 3 | A purchase that is not stock (a service, a custom item): is it a cost at receipt or at invoice, and to WIP or to expense? | P0-4 |
| 4 | Stock that arrives without a purchase order (cash purchase, opening balance, found stock): what does the other side of the entry represent in each case? | P0-5, P1-14 |
| 5 | Damage and waste: which account? If the material was held for a job, is it that job's cost? Who approves a write-off? | P0-6 |
| 6 | Stock issued to no project (workshop consumables): which department expense? | P0-6 |
| 7 | Production queue: synchronous postings or a supervised worker? | P0-7 |
| 8 | Do quote amounts include VAT? Confirms the WIP release fraction. | P0-8 |
| 9 | Work in Progress policy: capitalise or expense on capture. Still awaiting sign-off (Report 55). | WIP release, margin |
| 10 | Should a direct petty-cash payment with a job number remain a project cost at payment, or must all such spend go through requisition and accountability? | §13 |
| 11 | Short-delivered or abandoned orders: who closes them, and when? | P1-2 |
| 12 | Supplier credit notes and returns to supplier: the process WNG actually follows. | P1-4 |
| 13 | A cost arriving after a project is financially closed: refuse, reopen, or park? | P1-3 |
| 14 | Who may record a goods receipt and who may maintain suppliers? | P1-5, P1-6 |
| 15 | Materials bought with requisition cash: expensed to the job at accountability, or received into Stores and costed at issue? Not both. | P1-14 |
| 16 | Labour: does job labour enter the ledger's WIP, or stay analytical? How is it tied to payroll? | §17 |
| 17 | Payroll payment reversal. | §13 |
| 18 | Logistics and fleet costs (W8) and overhead allocation. | §16, §18 |

---

## 33. Verdict

**CROSS-MODULE FINANCIAL & COST FLOW AUDIT COMPLETE — IMPLEMENTATION PLAN REQUIRED**

| Priority | Count |
|---|---|
| P0 | 9 |
| P1 | 14 |
| P2 | 12 |
| P3 | 6 |

Two P0 findings were reproduced by execution (P0-1, P0-2). Three rest on rows in the rehearsal, dev or source-copy databases (P0-3, P0-5, P0-7). Four rest on code reading, one of them with a supporting data row (P0-4, P0-6, P0-8, P0-9).

**First recommended implementation phase: 76A — P0 accounting and cost integrity**, starting with 76A-0 (verify the target queue) and 76A-1 (the five code-only fixes), while WNG answers decisions 1 to 6 so that 76A-2 can follow.

Nothing was implemented. Stopped after Report 76.
