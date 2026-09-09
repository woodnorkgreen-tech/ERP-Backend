# Where Procurement, Stores and Finance disagree

**Date:** 2026-09-08 · **Scope:** the money trail from a purchase order through Stores to the ledger
**Method:** traced in code and then measured against the live development database. Every figure
below is a query result, not an estimate.

> Written for a non-accountant. Terms are spelled out in full; the glossary in
> [general-ledger-plan.md](./general-ledger-plan.md) covers anything unfamiliar.

---

## The short version

One problem is **live and measurable**: the ledger believes WNG owns a negative quantity of stock.
Two more are **structural and will bite as soon as the features involved are used**. Two are
smaller. And three things that looked like conflicts turned out to be sound — recorded here so
nobody spends a day re-auditing them.

| # | Finding | Severity | Live today? |
|---|---|---|---|
| 1 | Ledger inventory is **negative 76,580** while Stores holds **+55,910** | **Critical** | **Yes** |
| 2 | Stock counts change quantity with no accounting entry | High | Not yet — 0 counts approved |
| 3 | Staff labour and casual labour reach the same account on different timing rules | High | Not yet — dormant on both sides |
| 4 | The main cost-posting path bypasses the single writer built in Stage 0 | Medium | Yes, invisibly |
| 5 | Two competing payment-number generators, one of which races | Low | Rare |

---

## 1. The ledger thinks WNG owns negative stock · **critical, live**

| | |
|---|---|
| What Stores says the stock is worth | **55,910.00** |
| What the ledger's `1200 Raw-material Inventory` says | **−76,580.00** |
| Divergence | **132,490.00** |

A negative inventory asset is not a small error — it is an impossible statement. You cannot own
less than nothing.

**The cause is an absence, not a bug.** Tracing every movement on that account: the credits (stock
being issued out to jobs) heavily outnumber the debits (stock being received in). Material is being
relieved from an account it was never added to, because **the stock that existed before the ledger
started was never entered into it**. Stores knew about it; the ledger never did.

This is the "no opening balances" gap already scheduled as **Stage 6** of the general ledger plan.
What this audit adds is that it is **not a future problem** — it is already producing a nonsense
figure, and every report that touches inventory is wrong by 132,490 today.

**It also cannot be fixed by posting more accurately from here on.** Only an opening entry, dated at
a chosen cutover, closes it.

## 2. Counting the stock does not tell the accounts · **high** — *fixed 2026-09-08*

> **Closed the same day.** `StockMovementPostingService` now posts what a count found, inside the
> same transaction that changes the quantity, so the shelf and the accounts cannot disagree about
> what happened. Two accounts were added to the reference chart: **3900 Opening Balance Equity** and
> **6800 Inventory Adjustments & Shrinkage**.
>
> The distinction the tests protect is that opening inventory and a later count are **not the same
> event**. Opening stock is an asset against equity — it was already WNG's before the books opened,
> and there is no purchase behind it. A later count that finds less than the records claim has found
> a real loss, and a loss is an expense. Booking opening stock the second way would report the whole
> existing store as a loss in the month the books opened.
>
> A material with no recorded cost is left out of the value rather than counted as worth nothing;
> the quantity is still corrected. The Stores screen now states what approving will do to the
> accounts before anyone clicks, including how many items were left out for want of a cost.
>
> A count can no longer be approved into a closed month — the whole approval rolls back, because a
> count that moved the shelf but not the accounts is exactly the divergence this closes.
>
> Tests: 9 (`StockCountPostingTest`).

### The original finding



When Stores counts physical stock and finds a difference, the system corrects the quantity and
writes **no accounting entry at all**
([StockCountController.php:196](../app/Modules/ProcurementStores/Controllers/StockCountController.php#L196)).
Opening inventory behaves the same way: it sets quantities and unit costs, and posts nothing.

Measured: **0 stock counts approved, 0 inventory adjustments** — so this has not yet contributed to
finding 1. The moment Stores starts counting, it will, and silently: the two numbers drift further
apart with every count and nothing reports it.

This is **Stage 4** of the plan. Finding 1 raises its priority.

## 3. Two kinds of labour, one account, two different timings · **high**

`5200 Cost of Sales – Direct Labour` is now fed by two independent paths, and they follow different
rules about *when* a cost counts:

| Path | Who | When it hits the profit figure |
|---|---|---|
| Payroll split (Stage 2) | Salaried staff in a department marked "client work" | **Immediately**, in the month paid |
| Work in Progress release (Stage 2) | Casual labour captured as a project cost (`DL-CAS-001`, `DL-CAS-002`, `DL-ALW-001`) | **When the job is billed** |

Both are defensible alone. Together they mean two people doing the same work on the same job land in
the same account in **different months**, purely because one is on payroll and one is a casual.
Gross margin will move for reasons that have nothing to do with the business.

**It is not a double count.** The boundary holds: payroll covers staff, those three expense codes
cover casuals, and they are different people. But it is an inconsistent application of the matching
rule, and the reason for it is worth stating plainly: **labour cannot be attributed to a job**
because WNG records no hours against jobs, so staff pay has no job whose Work in Progress it could
wait in.

Measured: **0 cost lines use those three codes, 0 departments classified** — dormant on both sides.
The cheapest moment to decide the rule is now, before either is switched on.

## 4. The main cost path bypasses the single writer · **medium**

Stage 0 built `postBalancedEntry()` as the one door every journal entry goes through, enforcing four
rules: the month is open, every account is active and postable, debits equal credits, and one entry
number never produces two entries.

Client invoices, receipts, the Work in Progress release and payroll all go through it.
**`postCostLine()` does not** — the oldest and by far the highest-volume path still creates its
entry directly, with its own copy of three of those four rules.

The fourth is where they differ and it matters: the funnel **returns the entry that already exists**;
`postCostLine` would hit a duplicate-key error instead. Same intent, two behaviours, and the
inconsistent one is on the busiest path.

Not causing harm today. It is the shape that lets the two drift, which is the thing Stage 0 existed
to end.

## 5. Two payment-number generators, one racing · **low**

`BillPayment` numbers are produced two different ways:

- [BillController.php:391](../app/Modules/ProcurementStores/Controllers/BillController.php#L391) —
  `max(id) + 1`, computed before the insert
- [BillPayment.php](../app/Modules/ProcurementStores/Models/BillPayment.php) `generatePaymentCode()` —
  reads the last row and parses its number back out

The controller's version always wins, so the model's never runs. Both race: two concurrent payments
compute the same number. `payment_code` carries a unique index, so the result is a failed request
rather than two payments sharing a number — the damage is limited to a confusing error.

Same defect class as the `voucher_no` race already fixed on spend vouchers.

---

## Checked and sound — do not re-audit these

Three things that look like conflicts and are not. Recorded because each cost time to disprove.

**Payment methods versus payment sources.** Procurement keeps `payment_methods`; Finance keeps
`payment_sources`, which carry the ledger account. This looks like two competing tables and reads
that way in the code. It is not: `payment_methods.payment_source_id` bridges them, and
[SupplierPaymentService::record](../app/Modules/ProcurementStores/Services/SupplierPaymentService.php)
resolves the source from the method whenever only a method was given, refusing a mismatch. **Every
supplier payment reaches a real bank account.** The bridge works.

**Job identity reaching the ledger.** The Work in Progress release finds a job's costs by the
project stamped on each journal line. Measured: **zero Work in Progress lines are missing a job**, so
every cost captured so far can be released. `CostContextResolver` is doing its job.

**Document numbering.** `bill_number`, `po_number`, `grn_number` and `payment_code` all carry unique
indexes. Finding 5 is about how a number is *chosen*, not about whether duplicates can persist.

---

## What to do, in order

1. **Post opening inventory** (finding 1). Nothing else repairs a negative asset, and every
   inventory figure is wrong until it is done. **The mechanism is now built** — an opening-inventory
   count posts stock against equity — so this is waiting only on a business input: a cutover date and
   a valued stock list from Stores.
2. ~~**Make stock counts post** (finding 2).~~ **Done 2026-09-08**, ahead of finding 1 deliberately:
   the repair in step 1 runs through this path, so it had to exist first.
3. **Decide the labour rule** (finding 3) while both paths are still dormant. Either accept the split
   timing and document it, or hold staff labour out of cost of sales until job time is recorded.
4. **Route `postCostLine` through the funnel** (finding 4). A contained refactor with the tests
   already around it.
5. **One payment-number generator** (finding 5), using the pattern that fixed `voucher_no`.

Items 1 and 2 are Stages 6 and 4 of the general ledger plan. **This audit is a reason to bring them
forward**: the plan assumed the inventory account was merely incomplete, and it is actually wrong.
