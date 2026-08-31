# What the ledger is for, and what it is not

> Written 2026-08-19, after an audit of what the GL actually contained. Companion to
> [finance-gl-implementation-plan.md](./finance-gl-implementation-plan.md) (the build plan) and
> [cost-capture-audit.md](./cost-capture-audit.md) (the correctness audit). This one answers the
> prior question those two assume: **why does this system keep a ledger at all?**

---

## 1. The finding

At the time of writing the ledger held **6 journal entries touching 2 accounts**, both of them
assets — `1200 Raw-material Inventory` and `1211 Project WIP`. Every posting to date was
`Dr WIP / Cr Inventory`: a balance-sheet reclassification with no P&L effect whatsoever.

Structurally it could not have been otherwise:

| | Status |
|---|---|
| Revenue / AR | `ProjectInvoice` is an 18-line stub used by one controller. Posts nothing. The chart's 3 revenue accounts have never been touched. |
| Payroll | Nothing in `app/Modules/HR` references `JournalEntry`. |
| Bank, opening balances, equity | Do not exist. |
| Readers | Outside the journal module's own files, **nothing queried `journal_lines`.** Every cost report is a `GROUP BY` on `cost_lines`. |

So a P&L was impossible (no revenue side) and a balance sheet was impossible (no equity, no opening
balances). The `trial-balance` endpoint returned `is_balanced: true` — necessarily, since every
entry is constructed balanced. It proved the posting code worked and said nothing about the
business.

## 2. The decision

**WNG's statutory books are kept in an external accounting package.** WNG is VAT-registered, files
monthly VAT returns claiming input tax, and withholds and remits WHT to KRA.

Given that, a second general ledger in this system is not an asset — it is a second set of books
nobody reconciles, and two authoritative-looking numbers that will diverge. That is the same failure
the petty-cash board-request path caused once already.

So the ledger is **demoted from "general ledger" to what it genuinely is**:

> A **tax and audit subledger**. It holds transaction-level cost and tax detail that exists nowhere
> else in the business, produces the KRA schedules WNG files from, and hands the external package a
> periodic journal. It does not, and will not, prepare a financial position.

What that buys, ranked by value:

1. **The VAT input claim schedule and WHT remittance return.** Live obligations, monthly, with money
   attached. Nothing else in the business holds the detail they are made of.
2. **An immutable audit trail.** `cost_lines` are correctable; journals are not. Corrections are
   reversals. That asymmetry is the audit property and is worth keeping on its own.
3. **The `Dr WIP / Cr Inventory` discipline** that stops material being charged to a job twice.
4. **A document-batched export** to the external package.

## 3. What was built (2026-08-19)

### 3.1 Claim evidence — the hole that mattered most

`vat_treatments` has carried `requires_etims` and `claim_window_months` since the tax masters were
seeded, and `finance_settings` stated outright that the six-month window *"drives the eTIMS gap and
VAT claim schedule reports"*. Those reports could not be written: the system priced VAT to the cent
and recorded **nothing about the document it came off**. `cost_lines.evidence` held uploaded file
paths, and a scan of a receipt is not a claim reference.

Added to `cost_lines` (migration `2026_08_19_000001`):

| Column | Why |
|---|---|
| `etims_invoice_no` | The control number KRA matches the claim against. |
| `supplier_invoice_no` | What the supplier calls it, for their own query. |
| `supplier_pin` | **Snapshotted, not joined.** A filed return must keep showing the PIN it was filed under, or last quarter stops reconciling when a supplier record is corrected. |
| `tax_point_date` | The supplier's document date. **Separate from `incurred_at` on purpose** — for a Stores issue those are routinely months apart, and the claim window runs from the document. |

`CostTaxPricer::documentAttributes()` refuses to verify a recoverable, eTIMS-bearing line without
the number and the PIN. The gate is deliberately narrow — non-recoverable, exempt, out-of-scope and
zero-VAT spend all pass untouched, because a control that fires on ordinary spend gets worked
around. The preview announces the requirement (and the claim deadline) before anyone commits, and
still prices the split while doing so: hiding the numbers behind the document error would stop the
verifier seeing the VAT they are being asked to evidence.

**The PIN can be typed off the receipt**, and a typed one wins over the supplier record. Requiring
a supplier master before VAT could be reclaimed would have made the commonest claimable purchase in
the business — a hardware shop paid out of petty cash, printing its PIN on an eTIMS receipt —
unclaimable, and the workaround would have been to stop claiming it.

### 3.2 The two returns — `TaxScheduleService`

| Endpoint | What it answers |
|---|---|
| `GET /api/finance/tax/vat-input-schedule` | Input VAT claimable in a period. Dated by tax point, not consumption. Totals **only what can be substantiated** — an unsupported claim is excluded from the headline so nobody files it. |
| `GET /api/finance/tax/etims-gap` | Recoverable VAT with no eTIMS reference: how much is already forfeited, how much is still savable, ordered by **how soon each claim dies** rather than by value. |
| `GET /api/finance/tax/wht-schedule` | WHT deducted in a month by payee — one row per payee, which is how it is remitted and how a certificate is issued. |

All three answer as JSON for the screen and CSV for the filing pack, off one computation. Guarded by
`finance.reports.view`, since they carry third-party KRA PINs.

**The WHT schedule closes a gap `TaxResolver` documented against itself.** Its `withholding()`
tests `threshold_amount` against a single payment and notes that `aggregate_monthly` means KRA
intends the threshold to be tested against the supplier's *month* — "which needs a supplier-month
view that does not exist yet". The schedule is that view, so four payments of 20,000 under a 50,000
threshold now surface as a KES 2,400 under-withholding instead of staying invisible.

It is **reported, never auto-corrected**: the supplier was already paid in full, so recovering it is
a conversation Finance has to have, not a bookkeeping adjustment.

### 3.3 The hand-off — `LedgerExportService`

`GET /api/finance/journals/export` collapses the ledger to **one journal per source document** (GRN,
stores issue reference, PO, voucher), one row per account within it.

This is where the "should each material line be its own journal entry?" question lands. Internally,
one entry per cost line is right — each reverses independently and each carries its own source
document. Externally, a bookkeeper keying a fourteen-material delivery wants one journal for the
delivery note in their hand. **Batching is therefore a reporting concern resolved at the export**,
not a change to how the ledger is written: nothing about drill-back, reversal or audit changes to
gain it, and the collapse is a pure sum so totals cannot drift.

The payload carries an explicit `coverage` block naming what it excludes (revenue, payroll, bank,
opening balances) because the one way this file causes harm is being imported as a complete set of
journals.

### 3.4 Honesty on the trial balance

`GET /api/finance/journals/trial-balance` keeps its route but now returns a `coverage` block stating
`is_statutory_trial_balance: false` and listing what is absent. `is_balanced` is documented as an
integrity check on the posting code, not an accounting assertion.

### 3.5 Period close — `finance:close-period`

All 36 seeded periods were `open`, so `assertPeriodOpen()` had never fired and a cost could be
backdated into January 2024. The command runs a real month-end checklist:

- cost lines still submitted or queried in the month (**blocks** — closing pushes real spend into
  the wrong month)
- verified costs that never reached the ledger (**blocks** — a posting failure nothing else surfaces)
- unclaimable input VAT in the month (**warns**, with the figure)
- WHT to remit, and any aggregate-threshold under-withholding (**informs**)
- whether the month's journals balance (**blocks**)

A command rather than a migration or a cron: closing is a Finance decision about a month they have
finished reviewing, and a schedule would lock periods out from under people still working in them.

### 3.6 Smaller fixes

- `CostVerificationService::verify()` posted a journal unconditionally, while the producers gate on
  `accrued|actual`. A `committed` line routed through verification would have journalled a purchase
  order as spent money. Now gated the same way.
- `finance_settings` had no model, so every value in it was documentation. `FinanceSetting` reads it,
  effective-dated. `tax_return_due_day` seeded at 20 — **needs confirming with WNG's tax advisor**,
  in the same voice the capitalisation threshold and petty-cash cap already use.
- `finance:backfill-tax-document` gives pre-existing lines a tax point and a PIN. See §5.1.

## 4. What deliberately was not done

- **No revenue, payroll or bank posting.** That would be completing a general ledger, which is the
  decision this document argues against.
- **No internal batching of journal entries.** Answered at the export instead — cheaper and safer.
- **No "filed" state on the schedules.** Filing happens on KRA's portal; recording it here would be
  a claim the system cannot verify.
- **No "mark as filed" state.** Filing happens on KRA's portal; recording it here would be a claim
  the system cannot verify. The CSV is the working paper.

## 5. Open items, in priority order

1. **Run the backfill.** `finance:backfill-tax-document` sets `tax_point_date` from `incurred_at`
   and snapshots supplier PINs where a supplier record carries one; it never infers an eTIMS number,
   because a fabricated control number on a VAT return is fraud rather than a data-quality problem.
   It has only been dry-run so far (6 lines on dev, all Stores movements whose tax points will be
   approximate). Run it for real once Finance is ready to look at the resulting gap report.
2. ~~Frontend.~~ **Done 2026-08-19.** `CostTaxPanel.vue` renders the eTIMS number, KRA PIN and
   supplier invoice fields whenever `document.required`, shows the claim deadline as urgency
   ("expires in 12d") rather than a date, and surfaces the `etims_invoice_no` / `supplier_pin`
   errors it had been silently dropping. `TaxSchedulesView.vue` (`/finance/tax`) carries the three
   schedules as tabs with CSV download; the at-risk VAT banner follows the user onto every tab,
   because input tax with a deadline should not depend on anyone thinking to open the third one.
   The trial balance now prints its `coverage` note beside the totals.
3. **Confirm `tax_return_due_day`** and the claim window with WNG's tax advisor.
4. **Close the historic periods** once Finance has reviewed them — `finance:close-period --dry-run`
   first, per month.
5. Phase 3 items from `cost-capture-audit.md` that remain: the supplier-invoice producer and
   `releaseAccrual()` on the procurement side.
