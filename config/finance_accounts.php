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
    'map' => \App\Modules\Finance\Support\FinanceChartProfile::map(
        env('FINANCE_ACCOUNT_PROFILE'),
        env('FINANCE_WIP_POLICY'),
    ),

    /*
     | THE MAP COMES FROM A CHART PROFILE (Report 54)
     |
     | WNG keeps its QuickBooks chart (D3 Option A). The profile
     | database/finance/wng-chart-profile.json names, for every posting function,
     | the WNG account it posts to, including the accounts the chart lacked, which
     | `php artisan finance:complete-chart --profile=wng` creates. Set
     | FINANCE_ACCOUNT_PROFILE=wng to activate it. It is active in the rehearsal
     | checkout; production activation waits for the accountant's sign-off.
     |
     | FINANCE_WIP_POLICY picks HOW project cost is carried: `capitalise` (default,
     | what the engine implements: WIP until invoiced) or `expense_on_capture`
     | (WIP functions map onto their Cost of Sales twins). This is a Finance policy
     | and is still open. Creating the WIP accounts does not decide it.
     |
     | With no profile the map is empty: development and the test suite keep the
     | reference chart, where every code resolves to itself.
     */
    'profile' => env('FINANCE_ACCOUNT_PROFILE'),
    'wip_policy' => env('FINANCE_WIP_POLICY'),

    /*
     | Payment source code => the local account it is linked to (null = leave it
     | unlinked). Only a profile fills this: the seeder names every bank by one
     | generic reference code, so a map alone would link every bank to the same
     | account.
     */
    'payment_sources' => \App\Modules\Finance\Support\FinanceChartProfile::paymentSources(env('FINANCE_ACCOUNT_PROFILE')),

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