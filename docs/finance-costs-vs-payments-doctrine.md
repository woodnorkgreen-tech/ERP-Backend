# Finance: costs vs payments — recording and display doctrine

Canonical operating rule for how Finance captures, records, and displays
**costs** versus **payments**. Companion to
[finance-ledger-purpose.md](./finance-ledger-purpose.md) (why the subledger
exists) and [financial-truth-plan.md](./financial-truth-plan.md) (one event →
one treatment → one reversible journal).

---

## Verdict

**Costs** belong in `cost_lines` (project economic consumption).
**Payments** belong in `payments` / spend vouchers / bill settlements / client
receipts (cash custody). The operational tax/audit subledger posts journals
from those events — it is not the statutory books.

Canonical rule: **paying is not costing.** A supplier payment that settles an
already-accrued invoice must not create another project cost.

```
Business event
  ├─ consumption / commitment  →  cost_lines  →  verify  →  journals
  └─ cash in / out             →  payments / vouchers / receipts  →  journals
```

---

## 1. What to record where

| Business question | Record as | Table / document | Counts toward |
|---|---|---|---|
| Did this job consume value? | **Cost** | `cost_lines` (`planned` / `committed` / `accrued` / `actual`) | Project cost account **only when `status = verified`** |
| Did cash leave / arrive? | **Payment / receipt** | `payments`, `spend_vouchers`, `bill_payments`, `client_receipts` + `enquiry_payments` | Float / bank custody; AP/AR settlement |
| What do we claim/remit for KRA? | **Journal + tax evidence** | `journal_entries`/`lines` + eTIMS/PIN/tax-point on the cost | Tax schedules / export — not “profitability” alone |

### Cost natures (one trail, not parallel ledgers)

- **planned** — approved budget / revision
- **committed** — PO / fund request encumbrance (usually no journal)
- **accrued** — goods received, not yet actual consumption
- **actual** — real spend / issue / verified manual cost

Lifecycle: `draft → submitted → verified|queried|rejected` (verified can `reverse`).

### Payment kinds (cash documents)

- Outgoing: petty-cash / bank disbursement (`payments`), supplier settlement
  (`bill_payments` → `payments`), spend voucher
- Incoming: `client_receipts` (cash arrived) → `enquiry_payments` (allocation to
  project) → optional invoice match
- Paying account (`payment_sources`) is **where the money lives**;
  `payment_method` is **how it moved** — keep them independent

---

## 2. How each path should be captured

Use **Record spending** (`/finance/spend`) as the clerk entry point:

| Intent | Capture path | Cost effect | Payment effect |
|---|---|---|---|
| Already paid (receipt in hand) | Manual cost → verification | `actual` cost after verify + journal | Payment may already exist or be logged separately; do not double-expense |
| Need cash first | Fund requisition → pay | Producer writes verified cost on pay (**skip** if this payment is a supplier settlement) | `payments` + float ledger |
| Order from supplier | PO → GRN → (later) bill pay | PO = committed; GRN = accrued + journal; Stores issue = actual | Bill payment settles AP only |
| Settle existing liability | Payment voucher / bill payment | **None** if cost already on the job — voucher allocates to verified liabilities | Dr Payable / Cr bank |
| Client money in | Receivables: receipt → verify → allocate | None (funding, not cost) | Receipt journal on verify; invoice on issue; match clears deposits vs AR |

---

## 3. How they should be displayed

Nav order: **Project costs → Payments & cash → Client billing → Controls**.

1. **Project profitability / budget vs actual** — sum **verified** `cost_lines`
   by nature. Never sum `payments` as “job cost.”
2. **Cash position / float** — petty-cash ledger + payment sources. Never infer
   float from cost lines.
3. **AP outstanding** — bills minus `bill_payments`. Paying does not re-open
   cost capture.
4. **AR / funding** — invoices, receipts, allocations. Show “cash received” and
   “billed” as separate columns; do not mix into cost account.
5. **Operational ledger** — journals as audit/tax trail and export feed; label
   UI as operational/tax subledger, not company GL.
6. **One screen, one truth** — Cost account for one project = line drill-down;
   Budget vs actual = portfolio rows; Petty cash = custody; Vouchers =
   settlement workflow; Receivables = client money.

On the cost account, settlement of a cost (voucher / payment) is shown as
**payment status**, never as a second spend figure. Job margin uses billed
revenue against cost released to Cost of Sales (or verified actuals when the
chart posts straight to COS).

---

## 4. Recording invariants

- One economic event → one source record → one treatment → one reversible journal.
- Corrections = reverse + new; never silent mutate of posted journals.
- Verification prices VAT/WHT once (`CostTaxPricer`); preview must equal commit.
- Period must be open to post.
- Recoverable VAT needs eTIMS + supplier PIN + tax point date on the cost evidence.
- Transaction fees (`transaction_cost`) → overhead (e.g. 7800), **not** job cost,
  when there is no job attribution.
- Spend vouchers must allocate to existing verified liabilities so they do not
  double-expense.

---

## 5. Practical checklist

**Before saving, ask:**

1. Am I recording **consumption of value on a job**? → Cost collector / producer.
2. Am I recording **cash moving**? → Payment / voucher / receipt.
3. Was the cost **already** created by GRN/issue/prior verify? → Payment only.
4. Will Finance need this for **VAT/WHT**? → Put tax evidence on the cost (or
   bill), not only on the payment.

**When displaying a number, label which layer it came from** (`cost_lines` vs
payments vs journals) so two correct numbers are not treated as one wrong total.
