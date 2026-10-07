<?php

namespace App\Modules\Finance\Support;

use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Support\Collection;

/**
 * Says what chart a database holds (Reports 74A, 75). Read-only.
 *
 * Used by `finance:complete-chart`, which will not complete anything but the
 * company's own chart, and by Finance Setup, which shows the same answer to the
 * people who have to act on it. One definition, so the screen and the cutover
 * tool cannot disagree.
 */
final class ChartIdentity
{
    /**
     * What chart this database holds, read from the database and the profile only.
     *
     *  <PROFILE>_CHART_ONLY  every existing account the profile posts to is here,
     *                        and no reference-chart account is, other than the ones
     *                        the profile names (erp_added_accounts, a declared
     *                        reuse, or an account it maps to directly);
     *  TWO_CHART_STATE       the company's accounts are here AND reference-chart
     *                        accounts the profile does not name;
     *  OTHER / MANUAL_REVIEW_REQUIRED   anything else: the company's accounts are
     *                        missing, or there are four-digit accounts that are
     *                        neither the reference chart's nor the profile's.
     *
     * Reference accounts are recognised by CODE (the reference seeder's own list),
     * never by name. Nothing here changes anything, and nothing is concluded about
     * any account beyond whether it is present.
     *
     * @return array{identity: string, reason: string, counts: array<string, int>, blocking: ?string, ...}
     *
     * @param  Collection  $chart   chart_of_accounts rows keyed by code
     * @param  list<string>  $reused  existing accounts a profile entry declares for reuse
     * @param  array<int, int>  $usage  account id => journal lines
     */
    public static function inspect(string $profileName, array $profile, Collection $chart, array $reused = [], array $usage = []): array
    {
        $proposed = array_map(fn ($spec) => (string) ($spec['code'] ?? ''), (array) ($profile['new_accounts'] ?? []));
        $reused = array_map('strval', $reused);
        // Every account the profile itself points at, under any policy.
        $named = collect((array) ($profile['functions'] ?? []))->pluck('account')
            ->merge(collect((array) ($profile['wip_policies'] ?? []))->filter(fn ($p) => is_array($p))->flatMap(fn ($p) => array_values($p)))
            ->merge(collect((array) ($profile['catalogue'] ?? []))->pluck('account'))
            ->merge(array_values((array) ($profile['payment_sources'] ?? [])))
            ->filter()->map(fn ($code) => (string) $code)->unique()->values();
        $companyAccounts = $named->reject(fn ($code) => in_array($code, $proposed, true))->values();
        $missingCompany = $companyAccounts->reject(fn ($code) => $chart->has($code))->values()->all();
        $acknowledged = collect(array_keys((array) ($profile['erp_added_accounts'] ?? [])))->map(fn ($code) => (string) $code)
            ->merge($named)->merge($reused)->unique();

        $numeric = $chart->keys()->filter(fn ($code) => preg_match('/^\d{4}$/', (string) $code) === 1)->map(fn ($code) => (string) $code)->values();
        $referenceCodes = ChartOfAccountSeeder::referenceCodes();
        $reference = $numeric->filter(fn ($code) => in_array($code, $referenceCodes, true))->values();
        $unnamedReference = $reference->reject(fn ($code) => $acknowledged->contains($code))->values();
        $unexplainedNumeric = $numeric->reject(fn ($code) => in_array($code, $referenceCodes, true) || $acknowledged->contains($code))->values();
        $companyCoded = $chart->keys()->filter(fn ($code) => preg_match('/^[A-Z][A-Z0-9]*-\d+$/', (string) $code) === 1)->count();

        $duplicateNames = $chart->groupBy(fn ($a) => mb_strtolower(preg_replace('/\s+/', ' ', trim($a->name))))->filter(fn ($group) => $group->count() > 1)
            ->map(fn ($group) => $group->pluck('code')->map(fn ($code) => (string) $code)->all())->all();
        $withPostings = $unnamedReference->mapWithKeys(fn ($code) => [$code => (int) ($usage[$chart->get($code)->id] ?? 0)])->filter()->all();

        $own = strtoupper(preg_replace('/[^a-z0-9]+/i', '_', $profileName)).'_CHART_ONLY';
        $present = $companyAccounts->count() - count($missingCompany);
        [$identity, $reason, $blocking] = match (true) {
            $present > 0 && $unnamedReference->isNotEmpty() => ['TWO_CHART_STATE',
                "{$present} of the company's accounts are here together with {$unnamedReference->count()} reference-chart account(s) the profile does not name",
                "ACCOUNTANT REVIEW REQUIRED — TWO CHARTS DETECTED: this database holds the company's chart and {$unnamedReference->count()} reference-chart account(s)"
                .' ('.$unnamedReference->take(6)->implode(', ').($unnamedReference->count() > 6 ? ', …' : '').'), '.count($withPostings).' of them carrying journal lines.'
                .' Nothing is deleted, merged, moved, renamed, remapped or adopted automatically. An accountant decides which chart this database keeps;'
                .' a reference account is used only where the profile declares it (reuse_existing, or erp_added_accounts).'],
            $missingCompany === [] && $unexplainedNumeric->isEmpty() && $companyAccounts->isNotEmpty() => [$own,
                "all {$companyAccounts->count()} existing accounts the profile posts to are here, and no reference-chart account is beyond the ".$reference->count().' the profile names', null],
            default => ['OTHER / MANUAL_REVIEW_REQUIRED',
                ($missingCompany !== [] ? count($missingCompany).' account(s) the profile posts to are missing ('.implode(', ', array_slice($missingCompany, 0, 6)).(count($missingCompany) > 6 ? ', …' : '').'). ' : '')
                .($unexplainedNumeric->isNotEmpty() ? $unexplainedNumeric->count().' four-digit account(s) are neither the reference chart\'s nor named by the profile ('.$unexplainedNumeric->take(6)->implode(', ').'). ' : '')
                .($companyAccounts->isEmpty() ? 'The profile names no existing account.' : ''),
                'MANUAL REVIEW REQUIRED — this is not recognisably the chart profile \''.$profileName.'\' was written for. Nothing was changed; establish what this database holds before completing it.'],
        };

        return [
            'identity' => $identity,
            'reason' => trim($reason),
            'counts' => ['total' => $chart->count(), 'active' => $chart->where('is_active', true)->count(), 'postable' => $chart->where('is_postable', true)->count(),
                'company_coded' => $companyCoded, 'numeric' => $numeric->count(), 'reference' => $reference->count(),
                'reference_acknowledged' => $reference->count() - $unnamedReference->count(), 'reference_unnamed' => $unnamedReference->count(),
                'unexplained_numeric' => $unexplainedNumeric->count(), 'other_coded' => $chart->count() - $companyCoded - $numeric->count(),
                'profile_existing_accounts' => $companyAccounts->count(), 'profile_existing_missing' => count($missingCompany)],
            'reference_accounts_not_named_by_profile' => $unnamedReference->all(),
            'reference_accounts_with_postings' => $withPostings,
            'unexplained_numeric_accounts' => $unexplainedNumeric->all(),
            'profile_accounts_missing' => $missingCompany,
            'duplicate_names' => $duplicateNames,
            'blocking' => $blocking,
        ];
    }
}
