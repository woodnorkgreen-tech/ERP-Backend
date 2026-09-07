# Where Finance and Procurement stopped agreeing, and what was changed

> Written 2026-09-07, after tracing the finance reference-data seeders end to end against
> what actually consumes them, on both the API and the client. Companion to
> [finance-ledger-purpose.md](./finance-ledger-purpose.md) (what the ledger is for) and
> [procurement-purchase-categories.md](./procurement-purchase-categories.md) (the category
> vocabulary). This one answers a narrower question: **is the reference data these two
> modules share actually wired to anything?**

---

## 1. The finding

Mostly, no. The seeders were careful, well-documented and largely inert.

| Reference data | Seeded | Consumed |
|---|---|---|
| Chart of accounts | 100 accounts | Yes — 103 of 109 expense codes resolve a debit account |
| VAT / WHT | 5 + 3 | Yes, effective-dated |
| Payment sources | 6, each GL-backed | Petty cash, payroll, spend vouchers. **Never procurement.** |
| Cost centres | 18 | **Nothing. 0 of 109 expense codes, 0 of 72 cost lines.** |
| Activities | 27 | Partly — only where a live task supplied one |
| Cost causes / payee types | 7 + 4 | Yes |

Two whole classification dimensions were seeded, documented, exposed through a filter on
the cost account and a field on the verification screen — and referenced by nothing. Every
cost line the system had ever produced carried `cost_centre_id = NULL`.

The cause was mundane. `expense_codes` carries both a wording column and a foreign key for
each dimension (`default_cost_centre` / `default_cost_centre_id`). `ExpenseCodeSeeder`
filled only the wording. `CostContextResolver` reads only the key.

### 1.1 Why nobody noticed

`FinanceReadinessController` had a check for exactly this, and it counted rows in the
`cost_centres` table. Eighteen rows existed, so it passed — every day, while every posting
against it was null. A check that reports a dimension as configured when nothing references
it is worse than no check, because it is what stops anyone looking.

### 1.2 The knock-on in Procurement

`ProcurementCostProducer::postPurchaseOrder()` skipped any order line with no job:

```php
if (! $enquiryId && blank($jobNumber)) {
    continue; // Departmental procurement has no project cost object.
}
```

The goods-receipt path 40 lines below had no such guard. So a departmental purchase
committed nothing and then produced an accrual out of nowhere on delivery. In the live
database: **4 approved purchase orders, 0 commitments**, and one accrual —

```
CL-0000072   accrued   18,000.00
expense_code_id: NULL   project_enquiry_id: NULL   job_number: NULL
```

— eighteen thousand shillings classifiable on no axis at all. **Five of six requisitions in
the system are non-project**, so this was the ordinary path, not an edge.

The premise was the error rather than the handling. Departmental spend does have a cost
object: the department that asked for it. `requisitions.department_id` has always carried
it, and `cost_centres.hr_department_id` — a column created with the comment *"lets the
collector default a cost centre from the submitting user's HR department"* — has been
present, unwritten and unread, since the dimension tables were made.

### 1.3 Two of everything

| | Finance | Procurement |
|---|---|---|
| Where money leaves | `payment_sources` — 6 rows, each with a GL account, seeded | `payment_methods` — 7 free-text rows, no GL account, creatable through an open POST |
| Requisition categories | `petty_cash_requisition_types` — defined by **four migrations** | `expense_codes` — defined by a **seeder** |
| Requisition label | "Fund requisitions" | "Requisitions" / "Stock Requisitions" (two names for one screen) |

`PaymentSourceSeeder` states the principle it was built on — *"petty cash, bank, mobile
money and card are PAYMENT METHODS, not expense categories… each is a row here carrying its
own GL account, which is what lets one posting engine handle all of them identically"*.
That is precisely the job `payment_methods` was doing in parallel, without the GL account.
It also seeds an `AP — Supplier Credit (Payable)` source specifically so a credit purchase
settles through the same engine as a cash one. Procurement has never referenced it.

The migration-versus-seeder split matters more than it looks: seeders re-run on every
deploy and are the authority; migrations run once. So `db:seed` refreshed the expense
catalogue that fund-requisition categories are *derived from*, and left the categories
frozen wherever the last migration put them.

### 1.4 On the client

`/finance/spend` exists to remove the need to know how Finance is organised. It asked one
question — *"has the money already gone out?"* — and offered two answers. But WNG buys
things three ways: somebody pays and claims it back, somebody draws cash to go and buy it,
or Procurement orders from a supplier who invoices later. The third is the largest by
value and had **no door on that page at all**. "Has the money gone out" cannot separate a
cash draw from a purchase order — both answer no — and dressing three paths as a yes/no is
what hid one of them.

---

## 2. What changed

### 2.1 One authority for reference data

`DatabaseSeeder` listed seven finance seeders individually **and** called
`FinanceReferenceSeeder`, which ran the same seven in a different order. Idempotent, so
nothing corrupted — but neither list was authoritative. Collapsed to the aggregate, which
documents its own ordering.

`PettyCashRequisitionTypeSeeder` is new, and lifts the category list out of the four
migrations that held it. It does **not** restate whole rows the way the other seeders do,
because these types have an administrator's editor at `/finance/setup/requisition-types`.
Authority is split along the line the design already draws:

- `default_expense_code_id` and `requires_project` are re-asserted every run — the whole
  point of deriving a category from an expense code is that they cannot drift;
- name, recipient mode, questions, order and active flag are written once on creation and
  never touched again, so an administrator's work survives a deploy.

`default_payment_source_id` stays null on all 22. Which float a category is usually paid
from is an operational decision with a screen behind it, and `PaymentSourceSeeder` already
sets the precedent of leaving custodians and float limits to Finance.

### 2.2 The dimensions are real

- Every one of WNG's 13 departments now maps to exactly one cost centre through
  `hr_department_id`. Three cost centres were added so the mapping is total (`BRAND`,
  `CREW`, `COST`); the unmapped ones left are roll-up parents and cost pools that are not
  anybody's department — a hire fleet and a building are spent on, not staffed.
- `CatalogueDimensionMap` turns the catalogue's wording into dimension rows. It exists
  rather than a `LIKE` because the catalogue names *pairs* — "Production / Stores",
  "Stores / Production" — against a schema that holds one owning centre. **The first
  department named owns the cost**, which is how the catalogue's own examples read: material
  bought for a job is Production's cost that Stores handles; stock bought to hold is
  Stores' cost that Production draws on. The two orderings are not duplicates.
- `ExpenseCodeSeeder` resolves both keys. A missing dimension does **not** deactivate a
  code the way a missing debit account does: a cost with no cost centre is still a cost
  that happened and is still claimable, only less analysable.
- Migration `2026_09_07_000003` repairs what is already recorded. It restates no amount,
  account, period or balance — the dimension a cost belonged to was fixed by its expense
  code at capture and simply never written down, which is why it is also safe to apply to
  `journal_lines`, whose immutability protects what was posted rather than the analysis
  columns hanging off it. Every update is guarded on the column being null.

Result on the live database:

```
expense codes with a cost centre    0 → 108 of 109   (the 109th is "Asset-owning department")
expense codes with an activity      0 → 109 of 109
cost lines with a cost centre       0 → 37 of 72     (the rest are budget lines, which carry no code)
cost lines with an activity        34 → 71 of 72
journal lines with a cost centre    0 → 72 of 78
```

and, for the first time, spend groups by department:

```
PROD    Production               33 lines   142,490.00
LOG     Logistics & Transport     3 lines     9,400.00
FIN     Finance                   1 line          70.00
```

### 2.3 Cost centre resolution has an order

`CostContext` gained `costCentre` (a code) and `departmentId`. `CostContextResolver` takes
the most specific answer available:

1. what the producer states outright — Stores knows a movement is Stores';
2. the department that requested the spend — **the only cost object non-project spend has**;
3. the catalogue default, which says which department usually buys this kind of thing, and
   is explicitly the weakest answer.

A cost that reaches the end unclassified is still recorded. Refusing it would lose real
spend over a reporting attribute.

### 2.4 Departmental purchases are costed

The `continue` is gone and both procurement paths carry `department_id`, so a purchase is
committed when it is ordered and accrued when it arrives whether or not it belongs to a
job. `DepartmentalSpendTest` pins the behaviour, including that the requesting department
beats the catalogue default and that a purchase with no owner at all is still recorded.

### 2.5 One payment vocabulary

`payment_methods` now carries `payment_source_id`, and all seven existing rows resolve to a
GL-backed source. The familiar names stay on the payment screen — "Equity Bank" is more use
to a clerk than "Bank – Secondary" — while the account stays with Finance.

- `getPaymentMethods()` returns only methods that resolve to a source. A method without one
  names no ledger account, so a payment through it would appear on the bill and nowhere in
  the books.
- Both the single-bill and the **batch** payment endpoints validate against that. The batch
  path was checking only that the method row existed, which is where a control is most
  likely to be missed.
- `storePaymentMethod` now requires a payment source. That endpoint is how the second
  vocabulary grew: anyone could add a name, and the name was all there was.

### 2.6 The client says what it means

`/finance/spend` asks *"what do you need to do?"* and offers three doors — already paid,
need cash to go and buy it, supplier should deliver and invoice us. The third is not
permission-gated, deliberately: raising a requisition needs nothing more than being signed
in, and it is the approval that is controlled, not the asking.

The procurement requisition screen is called **"Purchase requisitions"** everywhere. It was
"Requisitions" in two places and "Stock Requisitions" in a third — the last being wrong as
well as inconsistent, since it also buys services, hire and printing.

### 2.7 The readiness check measures linkage

`cost_centres` and `activities` no longer pass on a row count. They report how much of the
catalogue resolves to a real dimension row, and **name the wording that does not**, because
the fix is always to teach `CatalogueDimensionMap` one more phrase.

### 2.8 Petty cash is a payment source for supplier invoices too

WNG pays most things out of the tin, supplier invoices included. That payment wrote a
`bill_payments` row and nothing else: the invoice showed as paid, the float did not move,
and the payment appeared nowhere in the petty cash transaction list — which reads
`petty_cash_ledger_entries` directly.

The payment form now asks **"paid from"** and lists payment sources rather than the
GL-less `payment_methods` names, so the choice carries a ledger account. When the source
is petty cash, `SupplierPaymentService` creates a real `PettyCashDisbursement` — allocated
against top-ups, logged, and posted through `LedgerEntry::debitForDisbursement()` — rather
than a bare ledger row. That matters because a disbursement is what the void, reporting and
reversal paths already understand; a custom entry would be invisible to all three.

Three things had to be completed for it to work:

| | |
|---|---|
| `bill_payments.payment_source_id` / `disbursement_id` | Declared in the model and written by the code, but the columns did not exist, so the path threw and took the whole disbursement transaction with it. |
| `petty_cash_ledger_entries.source_type` / `source_id` | `LedgerEntry` has always carried both and `LedgerService` reads them to stamp the balance projection — but `toRow()` dropped them, so they were never persisted. |
| The float balance on `GET /payment-sources` | Procurement staff do not hold `finance.petty_cash.view`, and somebody about to spend the tin needs to know what is in it. |

The ledger columns were not cosmetic. Once a disbursement paid from a bank posts **no** cash
entry — which is right, the money did not come out of the float — voiding one must not
credit the float either. The only way to know is to ask whether that disbursement ever
debited it, and `voidDisbursement()` was asking exactly that against columns that were not
there, so every void failed.

Guarded against double-debiting: a bill payment created **by** a disbursement carries
`disbursement_id`, and the cash has already left. Only a payment recorded directly on the
invoice settles the float.

### 2.9 A requisition now says which float paid it

Recording was never the problem — every payment record carries `payment_source_id`:
`petty_cash_disbursements`, `bill_payments`, and `spend_vouchers`. Even the third
vocabulary, the `payment_method` enum on a disbursement (`cash`, `mpesa`, `equity`,
`stanbic`…), is derived from the source in `CreateDisbursementRequest::prepareForValidation()`
rather than chosen independently, so it cannot disagree.

What was missing is that **a requisition could not tell you how it was paid.**

| | Before | Now |
|---|---|---|
| Fund requisition | The register loaded no disbursement at all. "Paid" was the whole answer. | Loads `disbursement.paymentSource`; the row reads "Paid · from Main Petty Cash Float", and the register filters on `payment_source_id` and on `settlement=paid\|unpaid`. |
| Purchase requisition | Four hops from its money — requisition → order → invoice → payment — and nothing bridged them. | `Requisition::settlements()` returns each payment with its source, amount, date and disbursement link, exposed on the detail view. |

Two deliberate choices in that:

- **The source is not copied onto the requisition.** A request is not paid from anywhere
  until somebody pays it, and a second copy of the answer is a second thing to keep in step.
  Both paths resolve through the payment record that actually moved the money.
- **`settlements()` is a method, not an eager-loaded relation.** A four-level join on every
  row of a paginated index would cost far more than the question is worth there; the detail
  screen asks about one request and can afford one query.

"Unpaid" is the absence of a disbursement rather than a status, because a request can sit
approved for weeks before the cash goes out.

### 2.10 The two ways petty cash pays a purchase requisition

A procurement requisition never gets paid directly — it becomes an order, the order is
delivered, the delivery is invoiced, and the invoice is what gets paid. Petty cash settles
that invoice by either of two routes, and both now run:

| | Route | What happens |
|---|---|---|
| **Pay after** | Open the invoice → *Record payment* → **Paid from: Main Petty Cash Float** | `SupplierPaymentService` creates a real disbursement, allocated against top-ups and logged, then the `BillPayment`. |
| **Draw first** | Open the invoice → *Pay from petty cash* → a fund requisition prefilled from the order's lines | Approved, collected, and paying it out creates the `BillPayment` against the invoice. |

The second route was entirely built and completely unreachable. The form reads `bill_id`
off the query, `PettyCashService` runs the same `SupplierPaymentGuard` three-way match
before the cash moves, and the disbursement creates the `BillPayment` — but no screen
linked to it, so every petty cash payment took the unlinked branch and no invoice was ever
matched. **0 of 23 requisitions carried a bill.** It has a door now, offered only when the
invoice is actually payable, since drawing cash for a blocked invoice produces a request
that is refused at payout.

Both routes debit the float exactly once. On the draw-first route the disbursement posts
the cash entry and the `BillPayment` it creates carries `disbursement_id`, which is what
stops the second posting.

Neither posts a project cost. The cost was recognised when the goods were received and is
retired when Stores issues them; this is settlement, and a cost here would charge the job
twice.

---

## 3. What was deliberately not done

- **The 18,000 orphan accrual is left alone.** It has no expense code, no job and no
  department because the purchase behind it never recorded one. Guessing an owner now would
  be worse than a visibly unattributed line; new receipts carry a department.
- **`payment_methods` is not dropped.** `bill_payments` has foreign keys into it and its
  names are what staff recognise. Making `payment_sources` the authority it resolves
  *through* achieves the coherence without rewriting settled history.
- **Paying a supplier bill still posts nothing to the COST ledger.** The cash side is now
  settled (§2.8), but the accrual raised at receipt is relieved when Stores issues the
  material to a job — correct for the WIP model, except that a departmental purchase is
  never issued, so its accrual has nothing to relieve it. That needs the accrual-to-expense
  path, not another dimension.
- **Budget lines still carry no cost centre.** They have no expense code by design — a
  budget line is a plan, and its cost object is the project.
- **`default_payment_source_id` is still null on every requisition type**, per §2.1.
- **There is no cash-purchase path for procurement.** Every supplier purchase must go
  order → delivery → invoice → verify → pay: a bill with no purchase order is unpayable by
  construction (`PurchaseOrderWorkflow::bill()` returns `can_pay: false` with "This invoice
  is not linked to a purchase order"). That is right for a credit purchase and wrong for
  the commonest one WNG makes — somebody takes cash to the hardware shop and comes back
  with a receipt. Closing it is a policy decision, not a coding one: whether a cash
  purchase still needs a GRN, what stands in for the supplier invoice, and under what
  threshold the three-way match is relaxed. `finance_settings.petty_cash_max_per_transaction`
  (seeded at 20,000, still unapproved) is the natural threshold and needs sign-off first.
- **No report groups spend by payment source.** Each requisition can now say what settled
  it, but "how much left the tin this month, across petty cash, procurement and payroll"
  has no single view. The data is there — every payment record carries the source — and the
  right home is a report over the payment records, not a `payment_source_id` on
  `cost_lines`: a cost and a payment are different events, one payment can settle many
  costs, and the system already separates them for that reason.
- **`is_procurable` still has no written definition.** `MaterialOptionsController` treats
  it as "no purchase order can carry this", and two petty-cash types default to
  non-procurable codes, which is defensible — but nobody has written down which meaning is
  intended.

---

## 4. Verification

- Backend: 762 passing across the whole suite (baseline 334 in the finance/procurement
  subset before any of this work).
- `SupplierPaymentGateTest` needed its fixture corrected: it created a payment method with
  no source, so it had been exercising exactly the unpostable payment the gate now refuses.
- Frontend `npm run type-check`: 240 pre-existing errors, none in any changed file.
