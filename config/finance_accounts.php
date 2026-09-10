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

        // ---- Control accounts that exist with same 4-digit codes in WNG chart
        '1030' => '1030',   // Petty cash float
        '2100' => '2100',   // Accounts payable
        '1100' => '1100',   // Accounts receivable
        '1200' => '1200',   // Raw-material inventory
        '1300' => '1300',   // Staff advances / imprest
        '1330' => '1330',   // Input VAT recoverable
        '2110' => '2110',   // Output VAT payable
        '2120' => '2120',   // Withholding tax payable
        '2150' => '2150',   // Accrued expenses
        '2200' => '2200',   // Client deposits
        '3900' => '3900',   // Opening balance equity
        '4100' => '4100',   // Project revenue
        '6800' => '6800',   // Inventory adjustments & shrinkage

        // ---- WIP accounts (1211-1219) map to COS accounts (5100-5900)
        // WNG has no WIP; costs go straight to COS on purchase
        '1211' => '5100',   // WIP direct materials      -> Cost of Sales: Direct Materials
        '1212' => '5200',   // WIP direct labour         -> Cost of Sales: Direct Labour
        '1213' => '5300',   // WIP subcontractors        -> Cost of Sales: Subcontractors
        '1214' => '5400',   // WIP transport & logistics -> Cost of Sales: Transport & Logistics
        '1215' => '5500',   // WIP equipment & site      -> Cost of Sales: Equipment & Site
        '1216' => '5600',   // WIP project utilities     -> Cost of Sales: Project Utilities
        '1217' => '5700',   // WIP project facilitation  -> Cost of Sales: Project Facilitation
        '1218' => '5800',   // WIP venue & statutory     -> Cost of Sales: Venue & Statutory
        '1219' => '5900',   // WIP rework & warranty     -> Cost of Sales: Rework & Warranty

        // ---- Production overhead (6xxx) - same codes exist in WNG chart
        '6100' => '6100',   // Workshop electricity
        '6200' => '6200',   // Machinery repairs & maintenance
        '6400' => '6400',   // Small tools & workshop consumables
        '6600' => '6600',   // PPE & workshop safety
        '6700' => '6700',   // Cleaning & waste disposal

        // ---- Operating expenses (7xxx) - same codes exist in WNG chart
        '7100' => '7100',   // Office rent & electricity
        '7150' => '7150',   // Office supplies & stationery
        '7200' => '7200',   // Administration airtime & internet
        '7400' => '7400',   // Office transport
        '7600' => '7600',   // Staff welfare
        '7800' => '7800',   // Bank & mobile-money charges

        // ---- Control accounts that may need creating (map to themselves for now)
        '1310' => '1310',   // Supplier advances (create if missing)
        '1320' => '1320',   // Refundable deposits (create if missing)
        '1340' => '1340',   // Prepaid expenses (create if missing)
        '1600' => '1600',   // Leasehold improvements (create if missing)
        '2300' => '2300',   // Loans payable (create if missing)

    ],

    /*
     * Does this installation keep the reference chart as its own?
     *
     * True where the ERP is the first system here to hold a chart at all —
     * development, the test suite, a fresh install. ChartOfAccountSeeder then
     * owns chart_of_accounts and re-asserts its accounts whenever it runs.
     *
     * False where the company brought its own, which is WNG's production case:
     * 123 mnemonic accounts imported from QuickBooks, with real postings behind
     * them. Seeding the reference chart there would stand 88 numeric accounts
     * up beside the real ones and leave two charts where the pickers expect
     * one. The `map` above is how the catalogue reaches a foreign chart; the
     * seeder must not touch it.
     *
     * Defaults off in production and on everywhere else, so the cost of getting
     * it wrong falls on a developer meeting an empty chart rather than on a live
     * ledger. Set FINANCE_SEED_REFERENCE_CHART=true on a genuinely fresh
     * production install that has no chart of its own.
     */
    'seed_reference_chart' => env('FINANCE_SEED_REFERENCE_CHART', env('APP_ENV') !== 'production'),

];