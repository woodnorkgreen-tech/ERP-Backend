<?php

namespace App\Modules\Finance\Governance;

use App\Modules\Finance\Services\WorkInProgressReleaseService;
use App\Modules\Finance\Support\FinanceAccountFunctions;
use App\Modules\Finance\Support\FinanceChartProfile;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;

/**
 * What approved Finance configuration says right now (Report 75): the read side
 * that posting, readiness and the account map consult.
 *
 * Only an ACTIVATED version whose in-force dates cover the date asked about
 * counts. A draft, a submission, an approval waiting to be activated, and an
 * activated version whose date has not arrived are all, to the ledger, nothing.
 *
 * WIP POLICY — who decides, in order:
 *
 *   1. An approved, active policy in the ERP governs.
 *   2. The deployment setting FINANCE_WIP_POLICY is a BOOTSTRAP: it is honoured
 *      only while the ERP holds no active policy, so the rehearsal and cutover
 *      tooling keep working. It is reported everywhere as "set by deployment,
 *      not approved in the ERP".
 *   3. If both exist and disagree, nobody is guessed right: CONFIGURATION
 *      CONFLICT, and project cost does not post.
 *   4. FINANCE_WIP_POLICY_AUTHORITY=governed retires the bootstrap: then only
 *      rule 1 can supply a policy.
 */
final class GovernanceRuntime
{
    public const WIP_ITEM = 'policy.wip';

    /** Long-running workers must not keep an old answer for the life of the process. */
    private const TTL_SECONDS = 5.0;

    /** @var array<string, array<string, array>> date => item key => value */
    private array $inForce = [];

    private float $loadedAt = 0.0;

    public static function instance(): self
    {
        $container = Container::getInstance();
        if (! $container->bound(self::class)) {
            $container->instance(self::class, new self);
        }

        return $container->make(self::class);
    }

    public static function flush(): void
    {
        Container::getInstance()->forgetInstance(self::class);
    }

    /**
     * Every item's value in force on a date.
     *
     * @return array<string, array>
     */
    public function inForce(?string $on = null): array
    {
        $on ??= now()->toDateString();
        if (microtime(true) - $this->loadedAt > self::TTL_SECONDS) {
            $this->inForce = [];
        }
        if (! isset($this->inForce[$on])) {
            $this->loadedAt = microtime(true);
            try {
                $this->inForce[$on] = DB::table('finance_config_versions')
                    ->where('status', FinanceConfigVersion::ACTIVE)
                    ->whereDate('in_force_from', '<=', $on)
                    ->where(fn ($q) => $q->whereNull('in_force_to')->orWhereDate('in_force_to', '>=', $on))
                    ->orderBy('in_force_from')
                    ->get(['item_key', 'value'])
                    ->mapWithKeys(fn ($row) => [$row->item_key => (array) json_decode((string) $row->value, true)])
                    ->all();
            } catch (\Throwable) {
                // No governance tables yet (a database that has not run the migration):
                // nothing has been decided here.
                $this->inForce[$on] = [];
            }
        }

        return $this->inForce[$on];
    }

    public function value(string $key, ?string $on = null): ?array
    {
        return $this->inForce($on)[$key] ?? null;
    }

    /**
     * The WIP policy as the ledger must treat it.
     *
     * @return array{policy: ?string, state: string, source: ?string, governed: ?string, deployment: ?string, message: ?string}
     */
    public function wipPolicy(?string $on = null): array
    {
        $governed = $this->value(self::WIP_ITEM, $on)['choice'] ?? null;
        $deployment = config('finance_accounts.wip_policy') ?: null;
        $bootstrap = config('finance_accounts.wip_policy_authority', 'transition') !== 'governed';
        $base = ['governed' => $governed, 'deployment' => $deployment];

        if ($governed !== null && $deployment !== null && $governed !== $deployment) {
            return $base + ['policy' => null, 'state' => 'conflict', 'source' => null,
                'message' => "CONFIGURATION CONFLICT — the approved project cost treatment in the ERP is '{$governed}', but the deployment setting FINANCE_WIP_POLICY says '{$deployment}'."
                    .' Neither is assumed. Remove the deployment setting, or bring it into line, before project costs can post.'];
        }
        if ($governed !== null) {
            return $base + ['policy' => $governed, 'state' => 'governed', 'source' => 'Approved and active in Finance Setup', 'message' => null];
        }
        if ($deployment !== null && $bootstrap) {
            return $base + ['policy' => $deployment, 'state' => 'deployment', 'source' => 'Deployment setting FINANCE_WIP_POLICY (not approved in the ERP)', 'message' => null];
        }

        return $base + ['policy' => null, 'state' => 'required', 'source' => null,
            'message' => 'POLICY REQUIRED — no project cost treatment is approved and active. Whether project cost is held as work in progress or recognised immediately'
                .' is a Finance decision, made in Finance Setup > Accounting Policies; no default is applied.'
                .($deployment !== null ? ' (The deployment setting is ignored: this installation takes the policy from the ERP only.)' : '')
                .' Project-cost postings and work-in-progress release are refused until it is active.'];
    }

    /**
     * The local account for a reference code where approved configuration decides
     * it, or null to leave the answer to the installed map.
     *
     * Only when a chart profile is active: without one the chart IS the reference
     * chart and there is nothing to redirect.
     */
    public function account(string $reference): ?string
    {
        $profile = config('finance_accounts.profile');
        if (blank($profile)) {
            return null;
        }
        static $functionByReference = null;
        $functionByReference ??= collect(FinanceAccountFunctions::all())->mapWithKeys(fn ($f, $key) => [$f['code'] => $key])->all();
        $function = $functionByReference[$reference] ?? null;
        if ($function === null) {
            return null;
        }
        if (! str_starts_with($function, 'wip_')) {
            return $this->value("mapping.{$function}")['account_code'] ?? null;
        }

        $policy = $this->wipPolicy()['policy'];
        if ($policy === null) {
            return FinanceChartProfile::WIP_POLICY_REQUIRED;
        }
        if ($policy === FinanceChartProfile::WIP_CAPITALISE) {
            return $this->value("mapping.{$function}")['account_code']
                ?? (FinanceChartProfile::load($profile)['wip_policies'][$policy][$function] ?? FinanceChartProfile::WIP_POLICY_REQUIRED);
        }
        // Recognised immediately: the cost lands on the function's cost-of-sales account,
        // wherever that is approved or mapped to be.
        $twin = WorkInProgressReleaseService::RELEASE_MAP[$reference] ?? null;
        $twinFunction = $twin ? ($functionByReference[$twin] ?? null) : null;
        if ($twinFunction === null) {
            return FinanceChartProfile::WIP_POLICY_REQUIRED;
        }

        return $this->value("mapping.{$twinFunction}")['account_code']
            ?? (config('finance_accounts.map.'.$twin) ?: (FinanceChartProfile::load($profile)['wip_policies'][$policy][$function] ?? FinanceChartProfile::WIP_POLICY_REQUIRED));
    }
}
