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

        /*
         | (Report 53, 2026-09-28) The identity entries that stood here ('1100' =>
         | '1100', …, added 2026-09-13) claimed those codes exist in WNG's chart.
         | They do not: WNG's chart is 120 QuickBooks accounts with mnemonic codes
         | (AR-001, AP-001, PETTY-001, EQB-001, COS-008, OPE-030, …). An identity
         | entry is also a no-op — an unmapped code already resolves to itself — so
         | removing them changes nothing except the false claim.
         |
         | The evidence-based mapping for WNG's chart (D3 Option A: WNG keeps its
         | chart) is database/finance/wng-coa-mapping-proposal.json, evaluated by
         | `php artisan finance:account-mapping`. A line is added here only once the
         | accountant has approved it; `finance:account-mapping --emit-map` prints
         | exactly the approved lines. Until then Finance posting refuses cleanly
         | (Finance readiness names every unresolved function).
         */

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