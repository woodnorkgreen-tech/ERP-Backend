# Purchase to pay: what happens after a purchase order is approved

_2026-09-05. Applies to the ProcurementStores module and the supplier-payment path in Petty Cash._

Approving a purchase order authorises a purchase. It does not mean the goods
arrived, that they were any good, or that WNG owes the supplier anything yet.
Before this change the system had no single answer to "where has this order got
to", so each screen guessed, and money could leave against an invoice nobody had
checked. This document records the sequence, the control, and where each lives.

## The sequence

| # | Stage | Owner | What has to happen |
|---|-------|-------|--------------------|
| 1 | Approval | Procurement / Accounts | The order is raised and approved. |
| 2 | Delivery | Procurement | The approved order reaches the supplier; a goods receipt is recorded when it arrives. |
| 3 | Stores check | Stores | Inspection, material identification, units and valuation. Only then is a line *accepted into stock*. |
| 4 | Invoice | Accounts | The supplier's invoice is recorded against the order, under the supplier's own invoice number. |
| 5 | Verification | Accounts | The three-way match below. |
| 6 | Payment | Accounts | Cash leaves, against a verified invoice. |
| 7 | Settled | — | Order, receipt, invoice and payment reference sit together. |

Delivery and consumption are separate events: issuing material to a project is
a Stores movement, not a step in settling the purchase.

## The control: a three-way match

`PurchaseOrderWorkflow::bill()` is the only place that decides whether an
invoice may be paid. It answers with named checks rather than a verdict, so a
blocked invoice always says which of order, receipt and invoice disagree:

1. The purchase order is approved.
2. The invoice supplier matches the order supplier.
3. The supplier's own invoice number is recorded.
4. Something has been accepted into stock by Stores.
5. **The invoice does not exceed the value accepted into stock.**
6. **The invoice does not exceed the approved order.**

Checks 5 and 6 are the money control. Over-billing is the leak worth stopping;
under-billing is not, because a part delivery is legitimately part invoiced.
"Everything ordered has arrived" is therefore a *stage* signal, not a payment
blocker — holding part deliveries hostage would stop the business working.

### Verification does not survive a change

A sign-off is a statement about particular figures. `verification_fingerprint`
hashes the order, its lines and prices, the accepted value, and the invoice's
supplier, reference and amount. If any of those change afterwards, the stored
fingerprint stops matching and the verification is withdrawn — Accounts checks
and signs again. Edits to the invoice row itself withdraw it directly, in
`Bill::withdrawVerificationOnChange()`.

## Where the gate is enforced

Three places create a `BillPayment`: the single-invoice screen, the batch
payment run, and a petty cash disbursement against a linked bill. The rule lives
in `SupplierPaymentGuard` and is enforced from `BillPayment::creating`, so a
fourth payment path inherits the control instead of having to remember it.

- **Single payment** — `BillController::recordPayment` calls the guard first and
  answers 422 with the reason, rather than letting the model throw a 500.
- **Batch payment** — `recordMultiBillPayment` evaluates every invoice before
  writing any, and refuses the run whole. Settling the clear ones and dropping
  the blocked ones would put one bank reference against a total that never left
  the bank.
- **Petty cash** — checked in `PettyCashService` *before* the disbursement is
  created, so a blocked invoice is refused with a reason rather than leaving
  cash out of the tin and nothing recorded against the supplier. The
  `BillPayment` creation inside that transaction now rethrows instead of
  logging, so cash out and the supplier ledger cannot drift apart.

## Invoices that pre-date the control

The migration stamps every existing bill `verification_basis = 'legacy'`. Those
invoices were raised before goods receipts were reliably recorded and could
never pass a match they were not built for; holding them to it would have frozen
every open supplier balance on the day this shipped. They stay payable, and every
screen says they pre-date the check rather than presenting them as matched.
Everything raised from here on answers to the match.

## What the screens read

Nothing in the UI decides any of this. `GET /purchase-orders/{id}/workflow`,
`GET /bills/{id}/verification` and `POST /purchase-orders/workflow-summary` all
return the same server answer, so the order page, the invoice page, the lists and
the payment gate cannot disagree about the same purchase.

## Still open

- **Advance payments.** Suppliers who require a deposit cannot be paid before
  delivery: there is no authorised exception path, by design, because the
  business has not yet chosen one. If WNG needs deposits, the shape to add is an
  explicit, named, audited override — not a hole in the gate.
- **Duplicate invoice numbers.** `supplier_invoice_number` is required on new
  invoices but not yet unique per supplier; a uniqueness constraint should
  follow once the legacy rows have references backfilled.
- **Who may verify.** Verification is currently open to any authenticated user
  who can reach the invoice. It should carry its own permission, and ideally not
  be available to whoever raised the invoice.
