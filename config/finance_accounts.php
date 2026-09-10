<?php

/*
|--------------------------------------------------------------------------
| Which chart of accounts this installation actually keeps
|--------------------------------------------------------------------------
|
| The expense catalogue in ExpenseCodeSeeder names its posting destinations by
| four-digit code — "1211 Project WIP – Direct Materials". Those codes are a
| *reference* chart: one internally consistent way to express the accounting,
| written so the catalogue could say what it means without waiting for any
| particular company's ledger.
|
| A company that keeps its books under different codes is not misconfigured.
| WNG's production chart is mnemonic — AR-001, KCB-001, COS-*, OPE-* — and it
| is the real one, with real postings behind it. So the reference code is the
| question and this map is the answer: canonical code => the code this
| installation actually uses.
|
| An empty map means the two agree, which is why development and the test
| suite need no entries. An unmapped code resolves to itself, so adding a
| mapping is additive and never silently moves an account that already worked.
|
| WHY THIS EXISTS AT ALL
|
| The seeder used to read the four digits straight out of that prose and look
| them up in chart_of_accounts. On a chart that does not use those numbers
| every lookup missed, every expense code was stamped inactive — is_active is
| set from whether the account resolved — and 107 of 109 codes vanished from
| the pickers with no error anywhere. The catalogue was coupled to one chart's
| numbering by a regular expression. This map is that coupling made explicit,
| and FinanceReadinessController reports what is still unmapped rather than
| letting it disappear.
*/

return [

    /*
     * canonical reference code => local chart_of_accounts.code
     *
     * Leave an entry out and it resolves to itself. Every code below is one the
     * catalogue posts to; the grouping is the catalogue's, and the comments say
     * what has to be true of the local account for the mapping to be right.
     *
     * The local account must be POSTABLE and ACTIVE. A header account resolves,
     * then fails at posting time, which is worse than not resolving at all.
     */
    'map' => [

        /*
         | DRAFT for WNG's chart, 7 September 2026. Every line is commented:
         | uncommenting one starts posting to that account, so this is a
         | proposal awaiting Finance's sign-off, not a configuration.
         |
         | READ THIS BEFORE UNCOMMENTING ANYTHING
         |
         | The reference chart capitalises project cost: 1211-1219 are Work in
         | Progress *assets*, debited as a job runs and released to cost of
         | sales when it completes. WNG's chart has no WIP accounts, so every
         | proposal below sends those costs straight to Cost of Sales instead.
         |
         | That is not a rename. It changes when cost hits the profit and loss
         | account — on purchase rather than on completion — so a job spanning
         | a month end no longer carries its cost forward to sit against the
         | revenue it earned. For an events business whose jobs are short that
         | may be entirely acceptable, and it is what QuickBooks appears to
         | have been doing already. It is still an accounting decision, and it
         | belongs to whoever signs the accounts.
         */

        // ---- Same thing, different name. These carry no model change.
        // '1030' => 'PETTY-001',   // Petty cash float          -> Petty cash
        // '2100' => 'AP-001',      // Accounts payable          -> Accounts Payable (A/P)
        // '1213' => 'COS-020',     // Subcontractors            -> Subcontractors - COS
        // '1214' => 'COS-016',     // Transport & logistics     -> Cost of Sales:Transport & Delivery
        // '1217' => 'COS-006',     // Project facilitation      -> Cost of Sales:Field Facilitation
        // '6200' => 'OPE-023',     // Machinery repairs         -> Repairs and Maintenance
        // '6400' => 'OPE-006',     // Small tools & consumables -> Consumables
        // '7150' => 'OPE-030',     // Office supplies           -> Stationery & Printing
        // '7200' => 'OPE-031',     // Airtime & internet        -> Telephone & Internet
        // '7400' => 'OPE-032',     // Office transport          -> Transport & Delivery
        // '7600' => 'OPE-029',     // Staff welfare             -> Staff Welfare

        // ---- Sound mapping, but WIP becomes an expense. See the note above.
        // '1211' => 'COS-008',     // WIP direct materials      -> Cost of Sales:Materials
        // '1212' => 'PE-007',      // WIP direct labour         -> Personnel Expenses:Wages-Direct Labour

        // ---- My reading is weaker here; these want a second opinion.
        // '1215' => 'COS-019',     // Equipment & site   — or OPE-013 Equipment Rental
        // '1216' => 'COS-019',     // Project utilities  — lands in Overhead - COS with the above
        // '1218' => 'COS-018',     // Venue & statutory  — Other - COS; no venue account exists
        // '1219' => 'COS-018',     // Rework & warranty  — shares Other - COS, so the two cannot be told apart
        // '6100' => 'OPE-012',     // Workshop electricity — shares Electricity & Water with the office
        // '6600' => 'OPE-006',     // PPE & workshop safety — Consumables is the closest, and it is not close
        // '6700' => 'OPE-014',     // Cleaning & waste  -> Garbage Collections (cleaning has no account)
        // '7100' => 'OPE-022',     // Office rent & electricity -> Rent & lease Payments; electricity splits to OPE-012
        // '7800' => 'FIN-003',     // Bank charges -> Finance cost:Bank charges; Mpesa splits to FIN-005

        /*
         | ---- NO COUNTERPART EXISTS. These cannot be mapped, only created.
         |
         | This is the real blocker, and it is bigger than the naming. WNG's
         | chart is a profit-and-loss chart: it carries expenses, banks, AR, AP
         | and equity, and almost no other balance-sheet control accounts. The
         | module posts to all of the following, and none of them exist:
         |
         |   1200  Raw-material inventory   (INV-001 is Inventory *Shrinkage*,
         |                                   an expense, not the stock asset)
         |   1330  Input VAT recoverable
         |   2150  Output VAT payable       (VP-001 is Vat *Penalty*)
         |   2120  Withholding tax payable
         |   2200  Client deposits
         |   1300  Staff advances
         |   1310  Supplier advances
         |   1320  Refundable deposits
         |   1340  Prepaid expenses
         |   1600  Leasehold improvements
         |   2300  Loans payable
         |
         | The first four matter most. Without an inventory asset the goods
         | receipt accrual has nothing to debit, so the stores flow this system
         | is built around cannot post at all; and without the VAT and WHT
         | accounts the tax schedules have nothing to accumulate against, which
         | is the reason this ledger exists.
         |
         | These have to be added to the chart. That is ordinary — any business
         | remitting VAT and WHT keeps them — and they may already exist in the
         | statutory books this chart was imported from.
         */

    ],

    /*
     | Does this installation keep the reference chart as its own?
     |
     | True where the ERP is the first system here to hold a chart at all —
     | development, the test suite, a fresh install. ChartOfAccountSeeder then
     | owns chart_of_accounts and re-asserts its accounts whenever it runs.
     |
     | False where the company brought its own, which is WNG's production case:
     | 123 mnemonic accounts imported from QuickBooks, with real postings behind
     | them. Seeding the reference chart there would stand 88 numeric accounts
     | up beside the real ones and leave two charts where the pickers expect
     | one. The `map` above is how the catalogue reaches a foreign chart; the
     | seeder must not touch it.
     |
     | Defaults off in production and on everywhere else, so the cost of getting
     | it wrong falls on a developer meeting an empty chart rather than on a live
     | ledger. Set FINANCE_SEED_REFERENCE_CHART=true on a genuinely fresh
     | production install that has no chart of its own.
     */
    'seed_reference_chart' => env('FINANCE_SEED_REFERENCE_CHART', env('APP_ENV') !== 'production'),

];
