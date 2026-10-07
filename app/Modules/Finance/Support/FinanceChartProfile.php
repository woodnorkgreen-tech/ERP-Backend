<?php

namespace App\Modules\Finance\Support;

/**
 * A company's chart profile: how the redesigned Finance meets a chart it did not
 * create (D3 Option A, Report 54).
 *
 * A profile is a JSON file, database/finance/{name}-chart-profile.json. It names:
 *  - the accounts the company's chart lacks, which `finance:complete-chart` creates,
 *    or, where the entry carries an explicit `reuse_existing` {code, name}, the one
 *    existing account that already meets that requirement (Report 74). Nothing is
 *    ever reused by name or by guess: only a declared code is, and the command
 *    refuses unless that exact account is there;
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

    /**
     * What every WIP function maps to while no WIP policy is in force. It is not an
     * account and can never be one (`finance:complete-chart` refuses the code), so
     * anything that resolves a WIP function through the map finds nothing: posting
     * refuses, the expense catalogue leaves its job codes unlinked, and readiness
     * says why. Mapping nothing instead would let the reference code resolve to
     * itself, and on a database that also holds the reference chart project cost
     * would quietly land on 1211.
     */
    public const WIP_POLICY_REQUIRED = 'WIP-POLICY-REQUIRED';

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
     * itself and is refused rather than guessed.
     *
     * HOW project cost is carried is Finance's decision and is never assumed. The
     * profile's `wip_policies.default` is a suggestion for a dry run to plan with,
     * NOT an authority: with no policy named here, or one the profile does not
     * define, every WIP function maps to {@see WIP_POLICY_REQUIRED} and resolves
     * to nothing. It used to fall back to the default, so activating a profile
     * without FINANCE_WIP_POLICY capitalised project cost on a policy nobody had
     * approved (Report 74A). Planning tools that want the suggestion ask for it by
     * name: {@see suggestedWipPolicy()}.
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
        $approved = filled($wipPolicy) && is_array($data['wip_policies'][$wipPolicy] ?? null);
        $byFunction = array_map(fn ($f) => $f['account'] ?? null, (array) ($data['functions'] ?? []))
            + ($approved
                ? (array) $data['wip_policies'][$wipPolicy]
                : array_fill_keys(self::wipFunctions(), self::WIP_POLICY_REQUIRED));

        $reuse = self::reuse($profile);
        $map = [];
        foreach ($byFunction as $key => $account) {
            if (filled($account) && isset($functions[$key])) {
                $map[$functions[$key]['code']] = $reuse[(string) $account] ?? (string) $account;
            }
        }
        foreach ((array) ($data['catalogue'] ?? []) as $reference => $entry) {
            if (filled($entry['account'] ?? null)) {
                $map[(string) $reference] ??= $reuse[(string) $entry['account']] ?? (string) $entry['account'];
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
        $reuse = self::reuse($profile);

        return array_map(fn ($account) => $account === null ? null : ($reuse[(string) $account] ?? $account),
            (array) (self::load($profile)['payment_sources'] ?? []));
    }

    /**
     * Proposed account code => the existing account the profile declares in its
     * place (`new_accounts[].reuse_existing.code`).
     *
     * This is the only way a required account is met by one the chart already
     * holds: the profile names that account's code. Every mapping that names the
     * proposed code then resolves to the existing one, so nothing else in the
     * profile changes. Whether the named account really is there, and is the
     * account the declaration says it is, is `finance:complete-chart`'s to refuse.
     *
     * @return array<string, string>
     */
    public static function reuse(?string $profile): array
    {
        $reuse = [];
        foreach ((array) (self::load($profile)['new_accounts'] ?? []) as $spec) {
            $target = is_array($spec['reuse_existing'] ?? null) ? ($spec['reuse_existing']['code'] ?? null) : null;
            if (filled($spec['code'] ?? null) && is_string($target) && filled($target)) {
                $reuse[(string) $spec['code']] = $target;
            }
        }

        return $reuse;
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
        if (blank($wipPolicy)) {
            $problems[] = "POLICY REQUIRED — chart profile '{$profile}' is active but no WIP policy is approved (FINANCE_WIP_POLICY is not set)."
                .' How project cost is carried ('.implode(' or ', self::wipPolicies($profile)).') is a Finance decision; the profile default is a suggestion and is never applied.'
                .' Project-cost postings and work-in-progress release are refused until it is set.';
        } elseif (! is_array($data['wip_policies'][$wipPolicy] ?? null)) {
            $problems[] = "POLICY REQUIRED — WIP policy '{$wipPolicy}' is not defined in chart profile '{$profile}', so no WIP function is mapped.";
        }
        $missing = array_diff(array_keys(FinanceAccountFunctions::all()),
            array_keys((array) ($data['functions'] ?? [])), self::wipFunctions());
        if ($missing !== []) {
            $problems[] = "Chart profile '{$profile}' does not map: ".implode(', ', $missing).'.';
        }

        return $problems;
    }

    /**
     * Catalogue reference codes the profile deliberately leaves unconfigured
     * (account null, with the reason recorded), e.g. WNG's loans payable: no
     * evidence of a loan. Codes posting there stay inactive by design, so readiness
     * reports them without counting them as a configuration gap.
     *
     * @return array<string, string> reference => reason
     */
    public static function intentionallyUnconfigured(?string $profile): array
    {
        return collect((array) (self::load($profile)['catalogue'] ?? []))
            ->filter(fn ($entry) => array_key_exists('account', (array) $entry) && $entry['account'] === null)
            ->map(fn ($entry) => (string) ($entry['note'] ?? $entry['meaning'] ?? 'not configured'))
            ->all();
    }

    /**
     * The policy the profile suggests, for a dry run to plan with. Never an
     * approval: nothing that posts may read this.
     */
    public static function suggestedWipPolicy(?string $profile): ?string
    {
        $default = self::load($profile)['wip_policies']['default'] ?? null;

        return is_string($default) && filled($default) ? $default : null;
    }

    /** @return list<string> the policies a profile defines */
    public static function wipPolicies(?string $profile): array
    {
        return array_keys(array_filter((array) (self::load($profile)['wip_policies'] ?? []), 'is_array'));
    }

    /** @return list<string> the posting functions whose account the WIP policy decides */
    public static function wipFunctions(): array
    {
        return array_values(array_filter(array_keys(FinanceAccountFunctions::all()), fn ($key) => str_starts_with($key, 'wip_')));
    }

    /**
     * Why nothing whose ledger treatment depends on the WIP policy may post right
     * now, or null when it may: no profile is active (the reference chart, which
     * has one treatment), or the active profile has an approved policy.
     */
    public static function wipPolicyBlock(): ?string
    {
        $profile = config('finance_accounts.profile');
        if (blank($profile) || self::load($profile) === null) {
            return null;
        }

        // The policy in force is the one approved in the ERP, or the deployment
        // bootstrap; a conflict between the two blocks as surely as having neither.
        $resolved = \App\Modules\Finance\Governance\GovernanceRuntime::instance()->wipPolicy();
        if ($resolved['policy'] === null) {
            return $resolved['message'];
        }

        return collect(self::problems($profile, $resolved['policy']))
            ->first(fn (string $problem) => str_starts_with($problem, 'POLICY REQUIRED'));
    }

    /**
     * Why the configured profile cannot be used right now, with the WIP policy
     * taken from wherever it is actually decided. What readiness reports.
     *
     * @return list<string>
     */
    public static function runtimeProblems(): array
    {
        $profile = config('finance_accounts.profile');
        if (blank($profile)) {
            return [];
        }
        $resolved = \App\Modules\Finance\Governance\GovernanceRuntime::instance()->wipPolicy();
        if ($resolved['policy'] !== null) {
            return self::problems($profile, $resolved['policy']);
        }
        // Structural problems of the profile itself, plus the reason there is no policy.
        $structural = array_values(array_filter(self::problems($profile, self::suggestedWipPolicy($profile)), fn ($p) => ! str_starts_with($p, 'POLICY REQUIRED')));

        return [$resolved['message'], ...$structural];
    }

    /**
     * Whether a catalogue debit text designates a work-in-progress account, i.e.
     * whether WHERE that cost lands is the WIP policy's to decide.
     */
    public static function dependsOnWipPolicy(?string $gl): bool
    {
        static $references = null;
        $references ??= array_map(fn ($key) => FinanceAccountFunctions::all()[$key]['code'], self::wipFunctions());

        return preg_match('/^\s*(\d{4})\b/', (string) $gl, $m) === 1 && in_array($m[1], $references, true);
    }

    /** Test seam: stand a decoded profile in for a file. */
    public static function fake(string $profile, ?array $data): void
    {
        self::$loaded[$profile] = $data;
    }

    /** Test seam: forget decoded profiles. */
    public static function flush(): void
    {
        self::$loaded = [];
    }
}
