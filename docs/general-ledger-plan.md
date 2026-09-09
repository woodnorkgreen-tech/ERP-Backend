# Turning the Finance module into a real general ledger

**Written:** 2026-09-08
**Decision taken by:** WNG (Cosmas), 2026-09-08
**Supersedes the decision in:** [finance-ledger-purpose.md](./finance-ledger-purpose.md) §2

> **Written for a non-accountant.** Every accounting term is spelled out in full and
> explained the first time it appears. There is a glossary at the end. If a sentence in
> this document needs you to already know accounting to follow it, that is a defect in
> the document — say so and it gets rewritten.

---

## 1. What is changing, in one paragraph

In August 2026 a decision was recorded that this system would deliberately **not** be a
complete set of accounting books. It would only record what things cost, produce the tax
schedules WNG files with the Kenya Revenue Authority, and hand a summary to an external
accounting package where the real books are kept. That decision is now reversed. This
system becomes the general ledger — the single, complete, official record of every
shilling that enters and leaves the business.

---

## 2. What a "general ledger" actually means

A **general ledger** is the complete list of every financial event in a business, written
in a way that always balances. "Balances" means: every event is recorded twice, once as
where the value came from and once as where it went. If you buy a laptop for 100,000
shillings, the ledger records both *"cash went down by 100,000"* and *"equipment went up
by 100,000"*. The two sides must always be equal. That is the entire discipline, and it
is what makes a ledger checkable rather than a list of claims.

Each of those two halves is called a **journal line**, and the pair (or group — some
events have four or five lines) is called a **journal entry**. Each line points at one
**account** — a named bucket like "Bank – Main Account" or "Project Revenue". The full
list of buckets is the **chart of accounts**. WNG's chart already exists and is well
designed: 100 accounts, seeded in
[ChartOfAccountSeeder.php](../app/Modules/Finance/Database/Seeders/ChartOfAccountSeeder.php).

The two words you will see constantly:

- **Debit** — the left-hand side. Increases things you own and things that cost you money.
- **Credit** — the right-hand side. Increases money you owe, money you have earned, and
  reduces things you own.

They are not "good" and "bad". They are just left and right, and they must total the same.

### What makes a ledger *complete* rather than partial

A ledger is complete when it can answer two questions without help:

1. **Did we make a profit?** This needs everything the business *earned* and everything it
   *consumed* in a period. The report is called the **Profit and Loss statement** (also
   written "P&L", also called the income statement).
2. **What is the business worth right now?** This needs everything the business *owns*,
   everything it *owes*, and the difference between them, which belongs to the owners. The
   report is called the **Balance Sheet**.

Today WNG's system can answer neither, for reasons set out next.

---

## 3. Where the system stands today, honestly

The cost side of the business is genuinely well built. The income side does not exist in
the ledger at all.

Here is every accounting event the system records today, for one 1,000,000-shilling job
with 160,000 shillings of Value Added Tax on top:

| # | What happens in the business | What the ledger records today |
|---|---|---|
| 1 | Client approves the quote for 1,160,000 | Nothing |
| 2 | Client pays a 580,000 deposit | A payment row. **No journal entry.** The cash never enters the ledger. |
| 3 | Project budget approved | A planned figure for comparison only. No journal entry — correct, a budget is not a transaction. |
| 4 | Purchase order approved for 300,000 of materials | A commitment against the job. No journal entry — correct, an order is a promise, not a transaction. |
| 5 | Materials delivered to the store | Debit Raw-material Inventory 300,000, credit Accrued Expenses 300,000. Correct. |
| 6 | Materials issued from the store to the job | Debit Project Work in Progress, credit Raw-material Inventory. Correct. |
| 7 | Supplier's invoice arrives and is checked | Debit Accrued Expenses and Input Value Added Tax, credit Withholding Tax Payable and Accounts Payable. Correct. |
| 8 | Supplier is paid | Debit Accounts Payable, credit Bank. Correct. |
| 9 | 40,000 of transport paid from petty cash | Debit Project Work in Progress – Transport, credit Petty Cash Float. Correct. |
| 10 | Staff paid for the month | Debit Salaries and Wages (all of it, as office overhead), credit the amounts owed to staff and to the Kenya Revenue Authority. **Partly wrong** — see §4.3. |
| 11 | Job delivered; invoice issued for 1,160,000 | **Nothing.** A status field changes. |
| 12 | Client pays the balance | A payment row. **No journal entry.** |

After all twelve steps the ledger contains inventory, work in progress, amounts owed to
suppliers, reclaimable tax, petty cash and some bank movement. It contains **no revenue,
no amounts owed by clients, no Value Added Tax owed to the Kenya Revenue Authority, and
none of the 1,160,000 shillings the client actually paid.**

The 700,000 shillings spent on the job sits in an account called **Project Work in
Progress** — which means "money spent on a job that is not finished yet" — and it stays
there permanently, because nothing ever moves it out when the job finishes.

---

## 4. The gaps, each explained plainly

### 4.1 Nothing records what WNG earns

**Accounts Receivable** means "money clients owe us". **Revenue** means "what we earned".
Neither is ever written to the ledger. Issuing an invoice
([EnquiryController.php:1498](../app/Modules/Projects/Http/Controllers/EnquiryController.php#L1498))
changes a status field and stops.

The invoice itself is a single header row — one subtotal, one tax figure, one total
([2026_08_11_000100_create_project_invoices.php](../database/migrations/2026_08_11_000100_create_project_invoices.php)).
It has no line items, so the tax amount is a number somebody types rather than one the
system works out.

### 4.2 Work in Progress never becomes a cost

When a job finishes and WNG bills the client, the money spent on that job stops being
"work in progress" (something WNG owns) and becomes **Cost of Sales** (what it cost to
earn that revenue). Matching the two in the same period is the whole basis of knowing
whether a job made money.

The accounts for this already exist — 5100 to 5900, one for each cost family — and
[the expense catalogue's own note](../app/Modules/Finance/Database/Seeders/OperationalExpenseCodes.php#L319)
says the transfer should happen. **No code anywhere does it.** Nothing in the system
references those accounts.

### 4.3 Staff cost does not reach the job, and part of it is not recorded at all

Payroll posts every shilling of gross pay to account 7550, "Salaries and Wages", which is
office overhead ([PayrollFinancePostingService.php](../app/Modules/HR/Services/Payroll/PayrollFinancePostingService.php)).
Two problems:

- A technician who spent the month building a client's stand is a **direct cost of that
  job**, not office overhead. Job costs are therefore understated and overheads overstated.
- Only money **deducted from** staff is recorded. The amounts WNG must pay **on top of**
  salaries — the employer's share of the National Social Security Fund, the Affordable
  Housing Levy — are an employer cost and are recorded nowhere.

### 4.4 Cash and bank barely exist

Petty cash is well built and reconciles. Above that there is nothing: no list of bank
accounts as proper records, no way to load a bank statement, and no **bank
reconciliation** (the monthly check that the ledger's idea of the bank balance matches
what the bank says). The Bank account in the ledger only moves when someone raises a
payment voucher or pays a payroll run.

### 4.5 The store and the ledger will drift apart

When Stores counts the physical stock and finds a difference, the system corrects the
quantity but writes **no journal entry**
([StockCountController.php:196](../app/Modules/ProcurementStores/Controllers/StockCountController.php#L196)).
So the ledger's inventory value and the store's actual inventory value diverge a little
more with every count, and nothing detects it.

### 4.6 Assets are a list, not an accounting record

The Assets module holds a register with a `current_value` column that a person types in.
There is no **depreciation** — the accounting method for spreading an asset's cost over
the years it is used. The accounts for it exist (1900 Accumulated Depreciation, 6500
Machinery Depreciation) and are never touched.

### 4.7 There is no starting point

A ledger that begins today with nothing in it says the business owns nothing and owes
nothing, which is false. **Opening balances** are the one-off entry that loads the true
position on a chosen start date. They do not exist, and neither does **equity** (the
owners' stake) or the **year-end close** (the annual step that moves the year's profit
into Retained Earnings so the next year starts clean).

### 4.8 Mistakes are hard to correct

In accounting you never erase an entry; you post an opposite one, which is called a
**reversal**. Reversal exists for cost lines only. Payment vouchers, supplier invoices,
supplier payments and payroll have **no reversal path at all** — correcting one means
editing the database by hand.

### 4.9 Two things write to the ledger

The design says one service writes journal entries. In fact
[JournalPostingService.php](../app/Modules/Finance/Services/JournalPostingService.php) and
[PayrollFinancePostingService.php](../app/Modules/HR/Services/Payroll/PayrollFinancePostingService.php)
both do, with separate validation. Two writers drifting apart is the exact failure that
already happened once in petty cash.

### 4.10 Month-end is a terminal command

Closing a month — declaring it final so nobody can slip a transaction into it afterwards —
runs only as a command typed into a terminal by a developer
([ClosePeriodCommand.php](../app/Modules/Finance/Console/ClosePeriodCommand.php)). There is
no screen and no way for Finance to do it.

### 4.11 Foreign currency is decorative

Columns for currency and exchange rate exist on journal lines, but there is no table of
exchange rates, no source for them, and no handling of gains and losses when rates move.

---

## 5. The plan

Nine stages. Each one is independently useful and independently testable, and each leaves
the system working. The order is driven by dependency and by risk, not by convenience.

### Stage 0 — Make the ledger safe to add to *(do first, always)*

Adding six more kinds of journal entry to a ledger that cannot be corrected and cannot be
closed multiplies a small problem into a large one. So before anything new posts:

1. **One writer.** Fold payroll posting into `JournalPostingService` so there is exactly
   one place in the codebase that creates a journal entry, with one set of rules.
2. **Reversal for everything.** A single `reverse(JournalEntry, reason)` that works for
   any document type, with the endpoint and the screen button to reach it.
3. **Period control Finance can operate.** Open, lock and close a month from a screen,
   with the existing month-end checklist shown as a checklist rather than terminal output.

**What you get:** every mistake made from here on is correctable by Finance without a
developer, and no transaction can appear in a month that was already reported.

> **Shipped 2026-09-08.** All three items are done and green.
>
> - **One writer.** `JournalPostingService::postBalancedEntry()` is now the single
>   method that creates a journal entry, and it applies the four rules that must hold
>   for every entry regardless of which document produced it: the month must be open,
>   every account must be active and postable, debits must equal credits, and one
>   `entry_no` must never produce two entries. `PayrollFinancePostingService` was
>   writing its own entries with its own copy of three of those rules and no copy of
>   the fourth; it now hands its legs to the funnel and keeps only the check no
>   generic writer could make — that gross pay, net pay and deductions reconcile
>   against the payslips.
> - **Reversal for everything.** `reverseEntry()` corrects any posted entry, whatever
>   document produced it, at `POST /api/finance/journals/{journal}/reverse` behind the
>   new `finance.journals.reverse` permission. Before this only a cost line could be
>   reversed — a mis-posted supplier invoice, supplier payment or payroll run could be
>   corrected only by editing the database by hand.
> - **Period control Finance can operate.** `PeriodCloseService` holds the month-end
>   checklist, and `finance:close-period` and the five new
>   `/api/finance/accounting-periods` endpoints are both callers, so the terminal and
>   the screen cannot disagree about whether a month may be closed. Locking, closing
>   and reopening are behind `finance.periods.manage`; reopening requires a reason and
>   records it, because a month that was reported on and then reopened is exactly what
>   an auditor asks about.
>
> Both new permissions are granted to **Accounts**, which already reverses costs and
> receipts. Withholding them would have left every correction and every month-end
> waiting on a developer, which is the state they exist to end.
>
> Tests: 18 new (`LedgerCorrectionTest`, `AccountingPeriodControlTest`). The Finance,
> Cost Collector and Petty Cash suites pass at **409 tests / 3,901 assertions**; HR at
> 39. One existing payroll assertion was updated — it pinned the old wording of the
> postable-account error, which moved into the shared writer; the rule it was
> protecting is unchanged and now applies to every document rather than only payroll.

### Stage 1 — Record what WNG earns

1. **Invoice line items.** A new `project_invoice_lines` table: description, quantity,
   unit price, and which Value Added Tax treatment applies. The tax is then *calculated*,
   not typed.
2. **Issuing an invoice posts a journal entry:**
   - Debit Accounts Receivable (1100) — the client now owes us the full amount
   - Credit Project Revenue (4100) — we earned the amount before tax
   - Credit Output Value Added Tax Payable (2110) — the tax portion belongs to the
     Kenya Revenue Authority, not to WNG
3. **Receiving client money posts a journal entry:**
   - Debit the bank or mobile-money account the money actually landed in
   - Credit Client Deposits (2200) — because a deposit received before invoicing is money
     WNG *owes back* until the work is done
4. **Allocating a receipt to an invoice posts a journal entry:**
   - Debit Client Deposits (2200), credit Accounts Receivable (1100) — the debt is settled
5. **Credit notes.** A proper document for reducing an invoice, which posts the reverse.

The existing three-level structure (`client_receipts` → `enquiry_payments` →
`project_invoice_allocations`) is good and stays; each level gains its journal entry.

**What you get:** the Value Added Tax return becomes possible for the first time — a
return is tax charged to clients *minus* tax paid to suppliers, and WNG currently records
only the second half. You also get, for the first time, a real answer to "what do clients
owe us".

> **Shipped 2026-09-08.** Items 1–4 are done and green. Credit notes (item 5) remain.
>
> - **Invoice line items.** `project_invoice_lines` carries description, quantity, unit price,
>   a Value Added Tax treatment and an optional revenue account. `InvoicePricer` resolves the
>   treatment **in force on the invoice date** — not today — so an invoice raised into last
>   month is charged at last month's rate, and prices the line from it. The header's
>   `subtotal` / `tax_amount` / `total_amount` are now **summed from the lines** rather than
>   typed, so the two can no longer disagree; every existing reader keeps working, including
>   the over-billing cap, which now measures the real total including tax.
> - **Issuing an invoice recognises revenue.** Debit Accounts Receivable for the whole amount,
>   credit Project Revenue for the amount before tax, credit Output Value Added Tax Payable for
>   the tax. Revenue legs are grouped by account, so a fourteen-line invoice produces one
>   revenue leg rather than fourteen identical ones, while still separating project revenue from
>   hire revenue. A zero-rated invoice raises **no** tax leg at all. The posting happens inside
>   the transaction that flips the status, so an invoice cannot end up issued-but-unposted —
>   the failure this codebase already met on spend vouchers.
> - **Client money posts on verification, not capture.** Debit the account the money landed in,
>   credit **Client Deposits — a liability**, because money received before the work is invoiced
>   is money WNG would have to give back. Crediting revenue there would book profit on a job
>   nobody has started.
> - **Matching a receipt to an invoice** debits Client Deposits and credits Accounts Receivable.
>   No cash moves; this only says which earning the money already held belongs to.
>
> **A real blocker this surfaced.** WNG's live chart of accounts has **no Client Deposits
> account and no Output Value Added Tax Payable account** — `config/finance_accounts.php`
> records both as having no counterpart. Every account above is resolved through
> `ChartAccountMap`, and `FinanceReadinessController` now requires 1100, 2110, 2200 and 4100, so
> the gap shows up on the readiness screen rather than at the moment somebody issues an invoice.
> **These two accounts must be created in WNG's chart before Stage 1 can post in production.**
>
> Tests: 10 new (`ReceivablesPostingTest`), asserting the accounting rather than the plumbing —
> that revenue is recognised once and only on issue, that a deposit is a liability, that every
> entry balances, and that a closed month refuses revenue. Finance, Cost Collector, Petty Cash,
> Projects and HR pass together at **564 tests / 4,551 assertions**.

### Stage 2 — Match cost to revenue

1. **When revenue is recognised, transfer that job's Work in Progress to Cost of Sales:**
   debit Cost of Sales (5100–5900), credit Project Work in Progress (1211–1219), family by
   family.
2. **Split payroll.** Hours worked on a job debit Project Work in Progress – Direct
   Labour; the rest stays as office overhead.
3. **Record employer statutory cost** — the employer's National Social Security Fund
   contribution and the Affordable Housing Levy — as an expense and a liability.

**What you get:** profit per job, in the ledger, calculated rather than estimated. This is
the single most valuable output of the whole plan.

> **Decisions taken 2026-09-08 (WNG):**
> - **Costs stay in Work in Progress until billed** — confirmed. 21% of WNG's jobs cross a
>   month end, which is where the alternative distorts.
> - **Tax invoices to clients are not required for now.** Recorded as a decision, revisitable.
>   It means the invoice is currently an internal accounting document rather than one the
>   client receives.
>
> **Shipped 2026-09-08.** Items 1–4 done and green, with one honest limit named below.
>
> - **Work in Progress releases into Cost of Sales when a job is billed.**
>   `WorkInProgressReleaseService` moves each cost family into its own twin — materials to
>   materials (1211→5100), subcontractors to subcontractors (1213→5300), and so on for all
>   nine — so a finished job still says what it spent on what. It posts in the same
>   transaction and on the same date as the revenue, because the whole point is that cost and
>   revenue land in the same month.
> - **Part-billed jobs release proportionally**, measured against the *original* cost total
>   rather than the remaining balance, so repeated billing converges on the right answer
>   instead of releasing a fraction of a fraction. Fully billed means fully released, by
>   whatever route.
> - **Payroll splits between delivering work and running the office.** Departments can be
>   marked `direct`; their people's pay debits Cost of Sales – Direct Labour instead of
>   Salaries & Wages. **Unclassified means overhead**, which is exactly what the system did
>   before — so shipping this restates nothing, and classifying a department is an improvement
>   somebody opts into.
> - **Employer statutory contributions are recorded at last.** The employer's National Social
>   Security Fund share was computed and posted nowhere; the employer's Affordable Housing Levy
>   share was never computed at all. Both now exist, follow the salary that caused them into
>   direct or overhead, and raise a matching liability. **The rates need confirming with WNG's
>   tax adviser** — they mirror the employee-side rates already in the file, which is the common
>   arrangement but not advice.
>
> **The limit, stated plainly: labour does not reach individual jobs, and cannot yet.**
> Attributing a person's pay to the jobs they worked on needs hours per person per job, and WNG
> does not collect them — `attendance_records` holds 0 rows and `task_time_entries`, a table
> that exists for exactly this, holds 0 rows. Spreading payroll across jobs by a formula would
> produce job margins that look precise and are invented; this codebase already learned that
> lesson with materials priced at budget. So the split buys a real **company-level** gross
> margin and stops there. **Per-job labour cost needs time capture first** — that is a business
> change, not a build.
>
> Tests: 16 new (`WorkInProgressReleaseTest` 9, `PayrollLabourSplitTest` 7). Finance, Cost
> Collector, Petty Cash, Projects and HR pass together at **586 tests / 4,627 assertions**.

### Stage 3 — Cash and bank

1. Bank accounts become proper records with an opening balance and a ledger account.
2. Every cash movement posts, not only vouchers and payroll.
3. Bank statement import and **bank reconciliation** — matching each ledger line to a line
   on the bank's statement, and showing what has not matched.

**What you get:** the ledger's cash figure can be *proved* against the bank, monthly.
Without this, every other number is only as trustworthy as the cash figure underneath it.

### Stage 4 — Make inventory true

1. Stock count differences post a journal entry (debit or credit Raw-material Inventory
   against a stock adjustment expense account).
2. Opening inventory posts its value, not just its quantity.
3. **Purchase price variance** — when material is issued at a planned price but bought at a
   different one, post the difference instead of hiding it. This closes the "valued at
   plan" compromise recorded in the August analysis.

**What you get:** the ledger's inventory value equals the store's, and can be proved.

### Stage 5 — Fixed assets

1. Buying an asset above the capitalisation threshold creates an asset record and debits
   the right asset account, instead of being written off as an expense.
2. A depreciation schedule per asset, and a monthly posting run.
3. Disposal — removing the asset and its accumulated depreciation, and recording the gain
   or loss on sale.

**What you get:** the balance sheet stops overstating what WNG owns, and the profit and
loss statement stops ignoring the cost of using equipment.

### Stage 6 — Opening balances and equity

1. Choose a **cutover date** — the day the ledger becomes official.
2. Load the true position on that date as one opening journal entry, taken from the
   external accounting package: bank balances, what clients owe, what WNG owes suppliers,
   inventory, assets, loans, and the owners' equity.
3. Build the **year-end close** — the annual step that moves the year's profit into
   Retained Earnings (3200).

**What you get:** a balance sheet that is true rather than one that starts from zero.

### Stage 7 — The reports

1. **Profit and Loss statement** — revenue minus cost of sales minus overheads, for any
   period, with comparison to the previous period.
2. **Balance Sheet** — what WNG owns, owes, and is worth, on any date.
3. **Cash Flow statement** — where cash actually came from and went.
4. **Trial balance becomes statutory** — the `is_statutory_trial_balance: false` flag that
   the system currently returns honestly can finally be set to true.
5. Accounts Receivable and Accounts Payable **ageing** — who owes what, and for how long.

### Stage 8 — Cutover: running both systems side by side

This is the stage that protects you from the risk the August decision was written about,
and it is not optional.

1. **Parallel run.** For two or three months, every transaction goes into *both* this
   system and the external package.
2. **Reconcile monthly.** Compare this system's trial balance to the external package's,
   line by line. Differences are investigated until they are zero or explained.
3. **Sign-off, then switch.** Only when a full month reconciles to nil does the external
   package stop being the official record.

**Why this matters:** until the switch, WNG has two sets of books. That is dangerous *only
if nobody reconciles them*. The parallel run is what makes it safe, and it needs a named
person at WNG doing it each month.

---

## 5a. Screens — every stage ships with the UI that operates it

**Standing rule, set by WNG on 2026-09-08:** *every backend implementation should come in
handy with its frontend, step by step.* Stages 0–2 were built backend-first, which produced
one live regression and three features nobody could reach. That debt was closed the same day
and the rule now applies to every slice.

| Screen | Operates | State |
|---|---|---|
| Client invoice form | Invoice lines, tax by rate | **Fixed** — it still posted the old `subtotal`/`tax_amount` shape, so every invoice creation from the browser had been failing since Stage 1 |
| `GET /api/finance/tax/treatments` | The rate list a sales line is priced from | **New** — no endpoint served this; the purchases side got rates from a cost-line preview, which a sales invoice has no equivalent of |
| Month-end close · `/finance/periods` | Period list, checklist, close / lock / reopen | **New** |
| Journal entry drawer | Correct a posted entry | **New** — the reverse button; before it, every document but a cost line needed a developer in the database |
| Finance setup → "What staff pay counts as" | Department labour classification | **New** — without it the payroll split could never be switched on |

Two things learned the hard way and worth keeping: **the finance navigation is a
hand-maintained array in `navigation.ts` AND the router — a new screen needs both entries**;
and **before changing any endpoint's request shape, grep the frontend for its callers.**

## 6. Sequence, effort and what each stage unlocks

| Stage | Rough effort | Unlocks |
|---|---|---|
| 0 · Safety | 1–2 weeks | Mistakes correctable; months closeable |
| 1 · Income | 2–3 weeks | Value Added Tax return; what clients owe |
| 2 · Matching | 2 weeks | **Profit per job** |
| 3 · Bank | 2–3 weeks | Cash provable against the bank |
| 4 · Inventory | 1–2 weeks | Stock value provable against the store |
| 5 · Assets | 2 weeks | Asset values and depreciation |
| 6 · Opening balances | 1–2 weeks | A true starting position |
| 7 · Reports | 2–3 weeks | Profit and Loss, Balance Sheet, Cash Flow |
| 8 · Cutover | 2–3 months elapsed | The external package can be retired |

Roughly **three to four months of build**, then a parallel run before switching over.

The order is deliberate. Stage 2 depends on Stage 1 (you cannot match cost to revenue that
is not recorded). Stage 7 depends on almost everything. Stage 0 comes first because it is
cheap and everything after it is riskier without it.

---

## 7. What WNG has to do, not what the software does

Three things need a person, and no amount of code substitutes:

1. **A cutover date and an opening trial balance** from the current external accounting
   package. Stage 6 cannot start without it.
2. **A named person to reconcile** during the parallel run in Stage 8.
3. **Confirmation of accounting policies** — the capitalisation threshold (the value above
   which a purchase becomes an asset rather than an expense), depreciation rates per asset
   type, and when revenue is recognised (on delivery, or on milestones). These are
   business decisions, not technical ones.

---

## 8. Glossary

| Term | Plain meaning |
|---|---|
| **Account** | One named bucket in the ledger, e.g. "Bank – Main Account" |
| **Accounts Payable** | Money WNG owes suppliers |
| **Accounts Receivable** | Money clients owe WNG |
| **Accrued Expenses** | Costs incurred but not yet invoiced by the supplier |
| **Ageing** | A report grouping debts by how long they have been outstanding |
| **Balance Sheet** | What the business owns, owes, and is worth, on one date |
| **Capitalisation threshold** | The value above which a purchase is treated as an asset rather than an expense |
| **Chart of accounts** | The full list of accounts |
| **Cost of Sales** | What it cost to deliver the work that earned the revenue |
| **Credit** | The right-hand side of an entry |
| **Credit note** | A document that reduces an invoice already issued |
| **Cutover date** | The day this system becomes the official record |
| **Debit** | The left-hand side of an entry |
| **Depreciation** | Spreading an asset's cost over the years it is used |
| **Equity** | The owners' stake — what is left after debts are taken from assets |
| **General ledger** | The complete, balancing record of every financial event |
| **Goods Received Note** | The record that a delivery arrived |
| **Journal entry** | One financial event, recorded as balancing lines |
| **Journal line** | One side of a journal entry, pointing at one account |
| **Opening balances** | The one-off entry loading the true position on the cutover date |
| **Output Value Added Tax** | Tax WNG charges clients and owes to the Kenya Revenue Authority |
| **Input Value Added Tax** | Tax WNG pays suppliers and can reclaim |
| **Parallel run** | Running both systems at once and reconciling, before switching |
| **Period close** | Declaring a month final so nothing more can be posted into it |
| **Profit and Loss statement** | Revenue minus costs, for a period |
| **Purchase price variance** | The difference between the planned and actual price of material |
| **Reconciliation** | Proving one record against an independent one |
| **Retained Earnings** | Accumulated profit kept in the business |
| **Reversal** | Correcting an entry by posting its opposite, never by erasing |
| **Revenue** | What WNG earned, before tax |
| **Three-way match** | Checking the order, the delivery and the invoice agree before paying |
| **Trial balance** | A list of every account's balance; the totals must be equal |
| **Withholding Tax** | Tax WNG deducts from a supplier's payment and remits to the Kenya Revenue Authority |
| **Work in Progress** | Money spent on a job that is not finished yet |
