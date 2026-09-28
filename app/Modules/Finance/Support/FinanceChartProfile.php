<?php

namespace App\Modules\Finance\Support;

/**
 * A company's chart profile: how the redesigned Finance meets a chart it did not
 * create (D3 Option A, Report 54).
 *
 * A profile is a JSON file, database/finance/{name}-chart-profile.json. It names:
 *  - the accounts the company's chart lacks, which `finance:complete-chart` creates;
 *  - for every posting function (FinanceAccountFunctions), the company account it
 *    posts to, including the two WIP timing policies;
 *  - the extra reference accounts the expense catalogue names;
 *  - each paying account's ledger account, named explicitly, because the seeder
 *    names every bank by one generic reference code.
 *
 * config/finance_accounts.php builds its `map` from the active profile
 * (FINANCE_ACCOUNT_PROFILE). With no profile, the map is empty and the chart is
 * the reference one: development and the test suite. A profile that cannot be
 * read gives an empty map as well, so posting refuses and readiness says why.
 * Nothing falls back to a guessed account.
 */
final class FinanceChartProfile
{
    public const WIP_CAPITALISE = 'capitalise';

    public const WIP_EXPENSE_ON_CAPTURE = 'expense_on_capture';

    /** @var array<string, array|null> */
    private static array $loaded = [];

    public static function path(string $profile): string
    {
        return database_path("finance/{$profile}-chart-profile.json");
    }

    /** The decoded profile, or null when the name is invalid or the file unreadable. */
    public static function load(?string $profile): ?array
    {
        if (blank($profile) || ! preg_match('/^[a-z0-9_-]+$/', (string) $profile)) {
            return null;
        }

        if (! array_key_exists($profile, self::$loaded)) {
            $path = self::path($profile);
            $decoded = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
            self::$loaded[$profile] = is_array($decoded) ? $decoded : null;
        }

        return self::$loaded[$profile];
    }

    /**
     * Reference code => local code for the posting functions and catalogue
     * references. An entry whose account is null is left out, so it resolves to
     * itself and is refused rather than guessed. An unknown WIP policy maps no WIP
     * function: the timing policy is Finance's, and it is never assumed.
     *
     * @return array<string, string>
     */
    public static function map(?string $profile, ?string $wipPolicy = null): array
    {
        $data = self::load($profile);
        if ($data === null) {
            return [];
        }

        $functions = FinanceAccountFunctions::all();
        $policy = $wipPolicy ?: ($data['wip_policies']['default'] ?? null);
        $byFunction = array_map(fn ($f) => $f['account'] ?? null, (array) ($data['functions'] ?? []))
            + (array) ($data['wip_policies'][$policy] ?? []);

        $map = [];
        foreach ($byFunction as $key => $account) {
            if (filled($account) && isset($functions[$key])) {
                $map[$functions[$key]['code']] = (string) $account;
            }
        }
        foreach ((array) ($data['catalogue'] ?? []) as $reference => $entry) {
            if (filled($entry['account'] ?? null)) {
                $map[(string) $reference] ??= (string) $entry['account'];
            }
        }

        return $map;
    }

    /**
     * Payment source code => local account code (null = leave unlinked). Empty
     * without a profile, where sources resolve through their reference code.
     *
     * @return array<string, ?string>
     */
    public static function paymentSources(?string $profile): array
    {
        return (array) (self::load($profile)['payment_sources'] ?? []);
    }

    /**
     * Why the configured profile cannot be used, if it cannot. Readiness reports
     * these; an empty list means no profile is configured, or it is sound.
     *
     * @return list<string>
     */
    public static function problems(?string $profile, ?string $wipPolicy): array
    {
        if (blank($profile)) {
            return [];
        }
        $data = self::load($profile);
        if ($data === null) {
            return ["Chart profile '{$profile}' is configured (FINANCE_ACCOUNT_PROFILE) but ".self::path((string) $profile).' cannot be read.'];
        }

        $problems = [];
        $policy = $wipPolicy ?: ($data['wip_policies']['default'] ?? null);
        if (! is_array($data['wip_policies'][$policy] ?? null)) {
            $problems[] = "WIP policy '{$policy}' is not defined in chart profile '{$profile}', so no WIP function is mapped.";
        }
        $missing = array_diff(array_keys(FinanceAccountFunctions::all()),
            array_keys((array) ($data['functions'] ?? [])), array_keys((array) ($data['wip_policies'][$policy] ?? [])));
        if ($missing !== []) {
            $problems[] = "Chart profile '{$profile}' does not map: ".implode(', ', $missing).'.';
        }

        return $problems;
    }

    /** Test seam: forget decoded profiles. */
    public static function flush(): void
    {
        self::$loaded = [];
    }
}
