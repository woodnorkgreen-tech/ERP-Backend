<?php

namespace App\Modules\Finance\Console;

use App\Modules\Finance\Support\FinanceAccountFunctions;
use App\Modules\Finance\Support\FinanceChartProfile;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Create the accounts a company's own chart lacks, then prove that every posting
 * function resolves (D3 Option A, Report 54).
 *
 * The profile lists the missing accounts. This command creates only those, and
 * only when they are missing:
 *  - code already present with the same definition -> left alone (idempotent);
 *  - code present with a DIFFERENT definition      -> refused, never overwritten;
 *  - an existing account with the same name under another code -> refused as a
 *    duplicate of that account (map to it instead);
 *  - an existing account is never modified, and ids are never touched.
 * Any refusal stops the whole run before anything is written.
 *
 * Dry run by default. --execute needs --confirm=<database name>. It never runs
 * against the live source, and against the live target only with --cutover.
 */
class CompleteChartCommand extends Command
{
    protected $signature = 'finance:complete-chart
        {--profile= : Chart profile (default: FINANCE_ACCOUNT_PROFILE)}
        {--connection= : Connection whose chart is completed (default: the app connection)}
        {--execute : Create the missing accounts (default: dry run)}
        {--confirm= : The target database name, typed exactly (required with --execute)}
        {--cutover : Allow execution against the live target database}
        {--output= : Directory for the JSON report}';

    protected $description = "Create the accounts a company's chart lacks for the redesigned Finance, and verify every posting function resolves";

    private const CATEGORIES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    private const ACCOUNT_TYPES = ['balance_sheet', 'direct_cost', 'overhead', 'opex', 'capex', 'revenue'];

    public function handle(): int
    {
        $profileName = (string) ($this->option('profile') ?: config('finance_accounts.profile'));
        $profile = FinanceChartProfile::load($profileName);
        if ($profile === null) {
            return $this->refuse(["Chart profile '{$profileName}' not found or unreadable (".FinanceChartProfile::path($profileName ?: '?').').']);
        }

        $db = DB::connection($this->option('connection') ?: null);
        $database = (string) $db->selectOne('SELECT DATABASE() AS db')->db;
        if (in_array($database, (array) config('source_migration.live_source_databases', []), true)) {
            return $this->refuse(["'{$database}' is the LIVE SOURCE. This command never touches it."]);
        }
        $execute = (bool) $this->option('execute');
        if ($execute && $this->option('confirm') !== $database) {
            return $this->refuse(["Execution requires --confirm={$database} (the target database name, typed exactly)."]);
        }
        if ($execute && in_array($database, (array) config('source_migration.live_target_databases', []), true) && ! $this->option('cutover')) {
            return $this->refuse(["'{$database}' is the LIVE target. Creating accounts there is a cutover step and requires --cutover."]);
        }

        $chart = $db->table('chart_of_accounts')->get()->keyBy('code');
        [$plan, $problems] = $this->plan((array) ($profile['new_accounts'] ?? []), $chart, $profile);
        if ($problems !== []) {
            return $this->refuse($problems);
        }

        $created = [];
        if ($execute) {
            $created = $db->transaction(fn () => $this->create($db, $plan));
            $chart = $db->table('chart_of_accounts')->get()->keyBy('code');
        }

        // After creation (or as if created, on a dry run): does everything resolve?
        $wouldExist = fn (?string $code) => $code !== null && ($chart->has($code) || isset($plan[$code]));
        $postable = function (?string $code) use ($chart, $plan): bool {
            if ($code === null) {
                return false;
            }
            $a = $chart->get($code);
            if ($a) {
                return (bool) $a->is_postable && (bool) $a->is_active;
            }

            return isset($plan[$code]) && $plan[$code]['action'] === 'create' && $plan[$code]['spec']['is_postable'];
        };

        $wipPolicy = config('finance_accounts.wip_policy') ?: ($profile['wip_policies']['default'] ?? null);
        $map = FinanceChartProfile::map($profileName, $wipPolicy);
        $functions = [];
        foreach (FinanceAccountFunctions::all() as $key => $f) {
            $local = $map[$f['code']] ?? null;
            $functions[$key] = ['reference' => $f['code'], 'account' => $local, 'resolves' => $postable($local),
                'name' => $chart->get((string) $local)?->name ?? ($plan[$local]['spec']['name'] ?? null)];
        }
        $catalogue = collect((array) ($profile['catalogue'] ?? []))->map(fn ($c, $ref) => [
            'account' => $c['account'] ?? null, 'resolves' => $postable($c['account'] ?? null), 'meaning' => $c['meaning'] ?? null,
        ])->all();
        $sources = collect((array) ($profile['payment_sources'] ?? []))->map(fn ($code) => [
            'account' => $code, 'resolves' => $postable($code),
        ])->all();

        $unresolved = array_keys(array_filter($functions, fn ($f) => ! $f['resolves']));
        $report = [
            'profile' => $profileName,
            'database' => $database,
            'mode' => $execute ? 'EXECUTE' : 'DRY RUN',
            'wip_policy' => $wipPolicy,
            'accounts' => array_values(array_map(fn ($p) => ['code' => $p['spec']['code'], 'name' => $p['spec']['name'], 'action' => $p['action']], $plan)),
            'created' => $created,
            'functions_resolved' => (count($functions) - count($unresolved)).' / '.count($functions),
            'unresolved_functions' => $unresolved,
            'functions' => $functions,
            'catalogue' => $catalogue,
            'payment_sources' => $sources,
        ];

        if ($dir = $this->option('output')) {
            @mkdir($dir, 0775, true);
            file_put_contents("{$dir}/chart_completion.json", json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->line("Report: {$dir}/chart_completion.json");
        }

        $this->line("{$report['mode']} — profile '{$profileName}' on '{$database}' (WIP policy: {$wipPolicy})");
        $this->table(['Code', 'Account', 'Action'], array_map(fn ($a) => [$a['code'], $a['name'], $a['action']], $report['accounts']));
        $this->table(['Function', 'Ref', 'Account', 'Name', 'Resolves'], collect($functions)->map(fn ($f, $k) => [$k, $f['reference'], $f['account'] ?? '—', $f['name'] ?? '—', $f['resolves'] ? 'yes' : 'NO'])->values()->all());
        $this->line('Functions resolved: '.$report['functions_resolved'].($unresolved ? ' — unresolved: '.implode(', ', $unresolved) : ''));
        $this->line('Catalogue references resolved: '.collect($catalogue)->where('resolves', true)->count().' / '.count($catalogue)
            .'; payment sources linked: '.collect($sources)->where('resolves', true)->count().' / '.count($sources));

        return $unresolved === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Decide, for every profile account, whether to create it or leave it; and
     * collect every reason the run must refuse.
     *
     * @return array{0: array<string, array{spec: array, action: string}>, 1: list<string>}
     */
    private function plan(array $specs, $chart, array $profile): array
    {
        $plan = [];
        $problems = [];
        $names = $chart->mapWithKeys(fn ($a) => [self::normal($a->name) => $a->code]);
        // Every account any mapping names, under either WIP policy (values only:
        // the policies share their keys, so a keyed merge would drop one of them).
        $mapped = collect((array) ($profile['functions'] ?? []))->map(fn ($f) => $f['account'] ?? null)->values()
            ->merge(collect((array) ($profile['wip_policies'] ?? []))->filter(fn ($policy) => is_array($policy))->flatMap(fn ($policy) => array_values($policy)))
            ->merge(collect((array) ($profile['catalogue'] ?? []))->map(fn ($c) => $c['account'] ?? null)->values());

        foreach ($specs as $i => $spec) {
            $code = (string) ($spec['code'] ?? '');
            $where = $code !== '' ? "Account {$code}" : "new_accounts[{$i}]";

            foreach (['code', 'name', 'category', 'normal_balance'] as $field) {
                if (blank($spec[$field] ?? null)) {
                    $problems[] = "{$where}: '{$field}' is required.";
                }
            }
            if (! in_array($spec['category'] ?? null, self::CATEGORIES, true)) {
                $problems[] = "{$where}: category '".($spec['category'] ?? '')."' is not one of ".implode('/', self::CATEGORIES).'.';
            }
            if (($spec['account_type'] ?? null) !== null && ! in_array($spec['account_type'], self::ACCOUNT_TYPES, true)) {
                $problems[] = "{$where}: account_type '{$spec['account_type']}' is not one of ".implode('/', self::ACCOUNT_TYPES).'.';
            }
            if (! in_array($spec['normal_balance'] ?? null, ['debit', 'credit'], true)) {
                $problems[] = "{$where}: normal_balance must be debit or credit.";
            }
            if (isset($plan[$code])) {
                $problems[] = "{$where} is listed twice in the profile.";

                continue;
            }
            $parent = $spec['parent'] ?? null;
            if ($parent !== null && ! $chart->has($parent) && ! isset($plan[$parent])) {
                $problems[] = "{$where}: parent {$parent} is neither in the chart nor listed before it in the profile.";
            }
            if ($parent !== null && isset($plan[$parent]) && ($plan[$parent]['spec']['is_postable'] ?? true)) {
                $problems[] = "{$where}: parent {$parent} is a new POSTABLE account; a new header must be non-postable.";
            }
            $spec['is_postable'] = (bool) ($spec['is_postable'] ?? true);
            if ($spec['is_postable'] && ! $mapped->contains($code)) {
                $problems[] = "{$where} is postable but no function or catalogue reference maps to it; it would be an orphan account.";
            }

            $existing = $chart->get($code);
            if ($existing) {
                $differences = $this->differences($existing, $spec, $chart);
                if ($differences !== []) {
                    $problems[] = "{$where} already exists with a different definition (".implode('; ', $differences).'). Existing accounts are never overwritten.';
                }
                $plan[$code] = ['spec' => $spec, 'action' => 'exists'];

                continue;
            }

            // A same-named account under another code is the same account.
            $leaf = self::normal($spec['name']);
            if (($other = $names->get($leaf)) !== null) {
                $problems[] = "{$where} ('{$spec['name']}') duplicates existing account {$other}. Map to {$other} instead of creating it.";
            }

            $plan[$code] = ['spec' => $spec, 'action' => 'create'];
        }

        return [$plan, $problems];
    }

    /** @return list<string> */
    private function differences(object $existing, array $spec, $chart): array
    {
        $differences = [];
        $expected = [
            'name' => $spec['name'], 'category' => $spec['category'], 'account_type' => $spec['account_type'] ?? null,
            'normal_balance' => $spec['normal_balance'], 'is_postable' => (int) $spec['is_postable'],
            'parent_id' => ($spec['parent'] ?? null) ? $chart->get($spec['parent'])?->id : null,
        ];
        foreach ($expected as $column => $value) {
            $actual = $column === 'is_postable' ? (int) $existing->{$column} : $existing->{$column};
            if ((string) $actual !== (string) $value) {
                $differences[] = "{$column}: has '".($actual ?? 'null')."', profile says '".($value ?? 'null')."'";
            }
        }

        return $differences;
    }

    /** @return list<array{code: string, id: int}> */
    private function create(Connection $db, array $plan): array
    {
        $created = [];
        $now = now();
        foreach ($plan as $code => $entry) {
            if ($entry['action'] !== 'create') {
                continue;
            }
            $spec = $entry['spec'];
            // Re-checked inside the transaction: never insert over a row that
            // appeared since planning.
            if ($db->table('chart_of_accounts')->where('code', $code)->lockForUpdate()->exists()) {
                throw new \RuntimeException("Account {$code} appeared while completing the chart; nothing was written.");
            }
            $id = $db->table('chart_of_accounts')->insertGetId([
                'code' => $code,
                'name' => $spec['name'],
                'category' => $spec['category'],
                'account_type' => $spec['account_type'] ?? null,
                'normal_balance' => $spec['normal_balance'],
                'parent_id' => ($spec['parent'] ?? null) ? $db->table('chart_of_accounts')->where('code', $spec['parent'])->value('id') : null,
                'is_postable' => $spec['is_postable'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $created[] = ['code' => $code, 'id' => (int) $id];
        }

        return $created;
    }

    private static function normal(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($name)));
    }

    private function refuse(array $problems): int
    {
        $this->error('REFUSED — nothing was written:');
        foreach ($problems as $problem) {
            $this->line("  - {$problem}");
        }

        return self::FAILURE;
    }
}
