# Two charts of accounts, and the map between them

_7 September 2026. Applies to the Finance module's posting destinations._

## What went wrong

The expense catalogue names every posting destination by four-digit code —
`1211 Project WIP – Direct Materials`, `7150 Office Supplies & Stationery`.
`ExpenseCodeSeeder` read those four digits back out of the prose with a regular
expression and looked them up directly in `chart_of_accounts`.

WNG's production ledger does not use those codes. It keeps a mnemonic chart:
`AR-001`, `AP-001`, `KCB-001`, twenty `COS-*` cost-of-sales accounts, thirty-two
`OPE-*` operating expense accounts. Every lookup therefore missed.

Missing is not the failure, though. The seeder sets

```php
$row['is_active'] = $row['default_debit_account_id'] !== null;
```

so a code whose account does not resolve is switched **off**. On 7 September the
catalogue seeded 109 codes against that chart, resolved 2, and deactivated 107 —
silently. No exception, no log line, nothing in the readiness endpoint, which
only inspects codes that are already active. The symptom reached the office as
"the expense types are missing from the dropdown", four layers away from its
cause.

The defect was never the numbering. It was that one chart's numbering had been
compiled into the catalogue by a regular expression, so a company with its own
chart had no way to say so.

## The shape of the fix

The four-digit codes stay, and are now understood as a **reference chart**: one
internally consistent way to express the accounting, so the catalogue can say
what it means without waiting for any particular company's ledger.

`config/finance_accounts.php` holds the answer for this installation:

```php
'map' => [
    '1211' => 'COS-003',   // Project WIP – Direct materials
],
```

`ChartAccountMap` is the only thing that turns a reference code into a real
account, so the seeder and the readiness check cannot disagree about where a
code posts. Three properties matter:

- **An unmapped code resolves to itself.** An empty map means the two charts
  agree — true in development and across the test suite, which is why none of
  them needed changing.
- **Mapping one code leaves the others alone.** A half-filled map redirects only
  what it names; it never moves an account that already posted correctly.
- **A blank value falls back rather than erasing.** A config template with
  `'1211' => ''` left in it must not resolve to the empty string, match nothing,
  and switch the code off — which is the original bug wearing a different hat.

## It now says so out loud

`FinanceReadinessController` gained `expense_code_mapping`, which counts
catalogue codes naming an account this chart does not have. It is deliberately
separate from the existing `expense_codes` check: that one inspects *active*
codes, so a code deactivated for want of a mapping leaves it perfectly clean
while being exactly the thing that emptied the pickers.

Codes naming an account indirectly — `Relevant 1400 PPE account`, `Receiving
cash/bank account` — carry no four-digit reference, stay unresolved on purpose,
and are not counted. They describe a class for a human to choose within, and
resolving one to a header account would hand the posting engine something it
cannot post to.

## Filling the map in

Roughly thirty accounts, not one per expense code — the 109 codes collapse onto
a small set of destinations. They group as nine project-WIP categories, five
production overheads, six operating expenses, and about eleven balance-sheet
control accounts.

Two rules for whoever completes it:

1. **The local account must be postable and active.** A header account resolves
   and then fails at posting time, which is worse than not resolving at all.
2. **The balance-sheet mappings are not filing decisions.** A misfiled expense
   is a report that reads oddly; a wrong control account is a wrong balance.

After editing the map, re-run the catalogue seeder and nothing else:

```
php artisan db:seed --class="App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder" --force
```

**Never `php artisan db:seed` on an existing ledger.** `ChartOfAccountSeeder`
upserts its own numeric chart and then deletes every account outside that list
which nothing references — on WNG's production chart that is most of the ledger.

## What WNG's chart turned out to be

The chart was read on 7 September 2026: 123 accounts, of which three are the
numeric strays below and the rest mnemonic. It is a profit-and-loss chart —
banks, receivables, payables, equity and a long tail of expense accounts,
evidently imported from QuickBooks. `account_type` is empty on every mnemonic
row.

Roughly nineteen of the reference accounts have a fair counterpart in it. The
mapping proposal sits, commented, in `config/finance_accounts.php`.

Two findings matter more than the names:

**Project cost has nowhere to capitalise.** The reference chart treats
`1211`–`1219` as Work in Progress assets, debited while a job runs and released
to cost of sales on completion. WNG's chart has no WIP accounts, so the proposal
sends those costs directly to `COS-*`. That moves when cost reaches the profit
and loss account — on purchase rather than on completion — and a job spanning a
month end stops carrying its cost forward against the revenue it earned. For
short event jobs that may be perfectly acceptable, and it is apparently what
QuickBooks was already doing. It is not a rename, and it is not ours to decide.

**Eleven control accounts do not exist at all.** Raw-material inventory, input
VAT, output VAT, withholding tax payable, client deposits, staff and supplier
advances, refundable deposits, prepayments, leasehold improvements, loans
payable. `INV-001` is Inventory *Shrinkage* and `VP-001` is VAT *Penalty* —
both expenses, neither the control account its name suggests.

The first four are load-bearing. Without an inventory asset the goods receipt
accrual has nothing to debit, so the stores flow cannot post; without the VAT
and WHT accounts the tax schedules have nothing to accumulate against, which is
the reason this ledger exists at all. No mapping can supply them. They have to
be added to the chart — ordinary enough for a business that remits VAT and WHT,
and they may already exist in the statutory books this chart came from.

So the sequence is: add the missing control accounts, decide the WIP question,
then fill in the map and re-run the catalogue seeder.

## Still open

- **`7150 Office Supplies & Stationery` is in production's chart and should not
  be.** The migration that widened the purchase catalogue inserted it so the two
  office-supplies codes could resolve. Once `OE-OFF-001` and `OE-OFF-002` map to
  a real `OPE-*` account, it can go.
- **`2160` and `7550` are also numeric strays**, inserted by
  `link_payroll_runs_to_finance`. Payroll posting may depend on them, so they
  need their own look rather than being swept up with `7150`.
- **The map is empty.** Until WNG's accountant fills it in, the catalogue stays
  dormant on production and the readiness check reports why.
