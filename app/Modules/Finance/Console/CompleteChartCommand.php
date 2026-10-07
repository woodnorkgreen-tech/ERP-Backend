<?php

namespace App\Modules\Finance\Console;

use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\Finance\Governance\GovernanceRuntime;
use App\Modules\Finance\Support\ChartIdentity;
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
 *    duplicate of that account. It is never adopted because the names agree;
 *  - a new account for a posting function whose reference-chart account is
 *    already in this chart -> refused: it would be a second account for one job;
 *  - `reuse_existing` {code, name} on a profile account (Report 74) -> nothing is
 *    created; that one existing account meets the requirement, and only if it is
 *    exactly the account declared (code AND name), active, and of the same
 *    postability, category, type and balance. Anything else is refused;
 *  - an existing account is never modified, and ids are never touched.
 * Any refusal stops an --execute before anything is written. A dry run goes on to
 * print the whole plan (what it would create, reuse and refuse, the classification
 * it would fill, the paying accounts), then exits non-zero.
 *
 * Two further steps run only when asked (Report 55):
 *  --classify-existing          fills account_type / normal_balance of the company's
 *                               own accounts from the profile's explicit list, ONLY where
 *                               they are NULL. A different non-null value is refused.
 *                               Nothing else about an existing account changes.
 *  --disable-unlinked-sources   disables the paying accounts the profile lists as
 *                               "disable until linked", only while they have no ledger
 *                               account and no document uses them.
 *
 * Every run first says WHAT CHART THIS IS (Report 74A): the company's own
 * (`<PROFILE>_CHART_ONLY`), the company's with the reference chart seeded beside
 * it (`TWO_CHART_STATE`), or anything else (`OTHER / MANUAL_REVIEW_REQUIRED`).
 * Only the first may be completed. On two charts the run stops with ACCOUNTANT
 * REVIEW REQUIRED: nothing is deleted, merged, moved, renamed, remapped or adopted
 * for anyone. The answer is read from this database alone.
 *
 * Dry run by default, and a dry run only reads. --execute needs --confirm=<database
 * name>. It never runs against the live source, and against the live target only
 * with --cutover and an approved WIP policy: the profile's default is a suggestion
 * to plan with, labelled as such, and never the authority for a cutover.
 */
class CompleteChartCommand extends Command
{
    protected $signature = 'finance:complete-chart
        {--profile= : Chart profile (default: FINANCE_ACCOUNT_PROFILE)}
        {--connection= : Connection whose chart is completed (default: the app connection)}
        {--execute : Create the missing accounts (default: dry run)}
        {--confirm= : The target database name, typed exactly (required with --execute)}
        {--cutover : Allow execution against the live target database}
        {--classify-existing : Also fill NULL account_type / normal_balance of existing accounts from the profile}
        {--disable-unlinked-sources : Also disable unlinked, unused paying accounts the profile lists}
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
        $usage = $this->journalUsage($db);
        // A default is not a decision. This tool may PLAN with the profile's
        // suggestion, and says so on every line that shows it; posting never reads it.
        // The policy in force: approved and active in Finance Setup, or, while the
        // ERP holds none, the deployment bootstrap (Report 75). A conflict between
        // the two is no policy at all.
        $resolved = GovernanceRuntime::instance()->wipPolicy();
        $approvedPolicy = $resolved['policy'];
        $wipPolicy = $approvedPolicy ?: FinanceChartProfile::suggestedWipPolicy($profileName);
        $wipPolicySource = match ($resolved['state']) {
            'governed' => 'approved and active in Finance Setup',
            'deployment' => 'configured (FINANCE_WIP_POLICY)',
            'conflict' => 'DEFAULT / NOT APPROVED FOR PRODUCTION — CONFIGURATION CONFLICT between Finance Setup and FINANCE_WIP_POLICY',
            default => 'DEFAULT / NOT APPROVED FOR PRODUCTION — no policy is approved in Finance Setup and FINANCE_WIP_POLICY is not set',
        };
        if ($execute && $this->option('cutover') && $approvedPolicy === null) {
            return $this->refuse([$resolved['message']." A live cutover is never run on the profile default '{$wipPolicy}'."]);
        }
        [$plan, $problems] = $this->plan((array) ($profile['new_accounts'] ?? []), $chart, $profile, $usage, $wipPolicy);
        // Always planned so a dry run can preview it; applied, and able to refuse, only when asked.
        [$classifyPreview, $classifyProblems] = $this->classificationPlan((array) ($profile['existing_classification']['accounts'] ?? []), $chart);
        $classify = $this->option('classify-existing') ? $classifyPreview : [];
        $warnings = $this->option('classify-existing') ? [] : $classifyProblems;
        $problems = [...$problems, ...($this->option('classify-existing') ? $classifyProblems : [])];
        $identity = ChartIdentity::inspect($profileName, $profile, $chart,
            collect($plan)->pluck('reuse')->filter()->values()->all(), $usage);
        if ($identity['blocking'] !== null) {
            array_unshift($problems, $identity['blocking']);
        }
        if ($problems !== [] && $execute) {
            return $this->refuse($problems);
        }
        $hasSources = $db->getSchemaBuilder()->hasTable('payment_sources');
        $sourcePreview = $hasSources ? $this->sourcePlan($db, (array) ($profile['payment_source_state']['disable_until_linked'] ?? [])) : [];
        $sources = $this->option('disable-unlinked-sources') ? $sourcePreview : [];

        $created = [];
        if ($execute) {
            $created = $db->transaction(function () use ($db, $plan, $classify, $sources) {
                $created = $this->create($db, $plan);
                foreach ($classify as $code => $columns) {
                    // Re-checked: only a column still NULL is written.
                    foreach ($columns as $column => $value) {
                        $db->table('chart_of_accounts')->where('code', $code)->whereNull($column)->update([$column => $value, 'updated_at' => now()]);
                    }
                }
                foreach ($sources as $code => $action) {
                    if ($action === 'disable') {
                        $db->table('payment_sources')->where('code', $code)->whereNull('gl_account_id')->update(['is_active' => false, 'updated_at' => now()]);
                    }
                }

                return $created;
            });
            $chart = $db->table('chart_of_accounts')->get()->keyBy('code');
        }

        // After creation (or as if created, on a dry run): does everything resolve?
        // A refused account resolves nothing, and neither does the account it named for reuse.
        $refused = collect($plan)->where('action', 'conflict');
        $blocked = $refused->keys()->merge($refused->pluck('reuse')->filter())->all();
        $reused = collect($plan)->where('action', 'reuse')->mapWithKeys(fn ($p, $code) => [$p['reuse'] => $code])->all();
        $status = function (?string $code) use ($chart, $plan, $blocked): string {
            if ($code === null) {
                return 'UNRESOLVED';
            }
            if (in_array($code, $blocked, true)) {
                return 'CONFLICT';
            }
            $a = $chart->get($code);
            if ($a) {
                return $a->is_postable && $a->is_active ? 'RESOLVED_EXISTING' : 'UNRESOLVED';
            }

            return isset($plan[$code]) && $plan[$code]['action'] === 'create' && $plan[$code]['spec']['is_postable'] ? 'RESOLVED_PROPOSED_NEW' : 'UNRESOLVED';
        };
        $postable = fn (?string $code) => str_starts_with($status($code), 'RESOLVED');
        $alias = FinanceChartProfile::reuse($profileName);
        $local = fn ($code) => $code === null ? null : ($alias[(string) $code] ?? (string) $code);

        $map = FinanceChartProfile::map($profileName, $wipPolicy);
        $created = collect($created)->pluck('id', 'code');
        $functions = [];
        foreach (FinanceAccountFunctions::all() as $key => $f) {
            $account = $map[$f['code']] ?? null;
            $existing = $chart->get((string) $account);
            $functions[$key] = ['reference' => $f['code'], 'account' => $account, 'resolves' => $postable($account),
                'name' => $existing?->name ?? ($plan[$account]['spec']['name'] ?? null),
                'status' => $status($account),
                'source' => match (true) {
                    $account === null => null,
                    isset($reused[$account]) => "existing account, reused for {$reused[$account]}",
                    $created->has($account) => 'created by this run',
                    $existing !== null => 'existing account',
                    isset($plan[$account]) => 'proposed new account',
                    default => 'not in the chart or the profile',
                },
                'account_type' => $existing ? $existing->account_type : ($plan[$account]['spec']['account_type'] ?? null),
                'normal_balance' => $existing ? $existing->normal_balance : ($plan[$account]['spec']['normal_balance'] ?? null),
                'is_postable' => $existing ? (bool) $existing->is_postable : ($plan[$account]['spec']['is_postable'] ?? null),
                'is_active' => $existing ? (bool) $existing->is_active : (isset($plan[$account]) ? true : null),
                'meaning' => $f['meaning']];
        }
        $catalogue = collect((array) ($profile['catalogue'] ?? []))->map(fn ($c, $ref) => [
            'account' => $local($c['account'] ?? null), 'resolves' => $postable($local($c['account'] ?? null)), 'meaning' => $c['meaning'] ?? null,
        ])->all();
        $sourceStates = $sources;
        $sources = $this->paymentSourceState($db, $hasSources, FinanceChartProfile::paymentSources($profileName), $postable, $sourcePreview);
        $twins = $this->referenceTwins($profile, $chart, $plan, $usage, $wipPolicy);
        $classification = $this->classificationPreview($profile, $chart, $classifyPreview);

        // An account mapping approved in Finance Setup that the profile does not reflect
        // (Report 75). This tool completes the chart from the PROFILE; if Finance has
        // since approved a different account, the profile must be brought into line
        // before a cutover, or an account would be created that nothing then uses.
        foreach (FinanceAccountFunctions::all() as $key => $f) {
            $approved = GovernanceRuntime::instance()->value("mapping.{$key}")['account_code'] ?? null;
            if ($approved !== null && $approved !== ($map[$f['code']] ?? null) && ! str_starts_with($key, 'wip_')) {
                $warnings[] = "Finance Setup has an approved, active mapping for '{$key}' to {$approved}; the profile plans ".($map[$f['code']] ?? 'none').'. Update the profile to match before a cutover.';
            }
        }

        $unresolved = array_keys(array_filter($functions, fn ($f) => ! $f['resolves']));
        $report = [
            'profile' => $profileName,
            'database' => $database,
            'mode' => $execute ? 'EXECUTE' : 'DRY RUN',
            'wip_policy' => $wipPolicy,
            'wip_policy_source' => $wipPolicySource,
            'wip_policy_approved' => $approvedPolicy !== null,
            'target_identity' => array_diff_key($identity, ['blocking' => 0]),
            'accounts' => array_values(array_map(fn ($p) => ['code' => $p['spec']['code'], 'name' => $p['spec']['name'], 'action' => $p['action'],
                'reuse' => $p['reuse'] ?? null, 'problems' => $p['problems'] ?? []], $plan)),
            'created' => $created->map(fn ($id, $code) => ['code' => $code, 'id' => $id])->values()->all(),
            'functions_resolved' => (count($functions) - count($unresolved)).' / '.count($functions),
            'unresolved_functions' => $unresolved,
            'functions' => $functions,
            'catalogue' => $catalogue,
            'payment_sources' => $sources,
            'classified_existing' => array_map(fn ($c) => array_keys($c), $classify),
            'classification_preview' => $classification,
            'source_state' => $sourceStates,
            'reference_twins' => $twins,
            'blocking' => $problems,
            'warnings' => $warnings,
        ];

        if ($dir = $this->option('output')) {
            @mkdir($dir, 0775, true);
            file_put_contents("{$dir}/chart_completion.json", json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->line("Report: {$dir}/chart_completion.json");
        }

        $this->line("{$report['mode']} — profile '{$profileName}' on '{$database}' (WIP policy: {$wipPolicy}, {$wipPolicySource})");
        $c = $identity['counts'];
        $this->line("Target chart identity: {$identity['identity']} — {$identity['reason']}");
        $this->line("Chart of accounts: {$c['total']} accounts ({$c['active']} active, {$c['postable']} postable); {$c['company_coded']} company-coded, {$c['numeric']} four-digit"
            ." ({$c['reference']} reference-chart, of which {$c['reference_acknowledged']} named by the profile); {$c['other_coded']} other; "
            .count($identity['duplicate_names']).' duplicated name(s); '.count($identity['reference_accounts_with_postings']).' reference account(s) carrying journal lines');
        foreach ($identity['duplicate_names'] as $name => $codes) {
            $this->line("  - duplicated name '{$name}': ".implode(', ', $codes));
        }
        foreach ($identity['reference_accounts_with_postings'] as $code => $lines) {
            $this->line("  - reference account {$code} '{$chart->get($code)->name}': {$lines} journal line(s)");
        }
        $this->table(['Code', 'Account', 'Action'], array_map(fn ($a) => [$a['code'], $a['name'],
            $a['action'] === 'reuse' ? "reuse {$a['reuse']}" : ($a['action'] === 'conflict' ? 'CONFLICT' : $a['action'])], $report['accounts']));
        $count = fn (string $action) => collect($plan)->where('action', $action)->count();
        $this->line("Accounts: {$count('create')} to create, {$count('exists')} already present, {$count('reuse')} met by an existing account (declared reuse), {$count('conflict')} in conflict");
        $this->table(['Function', 'Ref', 'Account', 'Name', 'Resolves', 'Status'], collect($functions)->map(fn ($f, $k) => [$k, $f['reference'], $f['account'] ?? '—', $f['name'] ?? '—', $f['resolves'] ? 'yes' : 'NO', $f['status']])->values()->all());
        $this->line('Functions resolved: '.$report['functions_resolved'].($unresolved ? ' — unresolved: '.implode(', ', $unresolved) : ''));

        $pending = array_keys($classification['pending_decision']);
        if ($this->option('classify-existing')) {
            $this->line('Existing accounts classified (NULL columns filled): '.count($classify).' account(s)'.($execute ? '' : ' (dry run)').'; pending decision: '
                .implode(', ', array_keys((array) ($profile['existing_classification']['unclassified_pending_decision'] ?? []))));
        } else {
            $this->line('Classification preview (not applied; --classify-existing applies it): '.count($classification['would_fill'])
                .' account(s) would have a NULL account_type / normal_balance filled; left for the accountant: '.($pending ? implode(', ', $pending) : 'none')
                .'; unclassified and not in the profile: '.($classification['not_in_profile'] ? implode(', ', array_column($classification['not_in_profile'], 'code')) : 'none'));
        }
        if ($this->option('classify-existing') || $this->getOutput()->isVerbose()) {
            $this->table(['Code', 'Account', 'Type now', 'Balance now', 'Type proposed', 'Balance proposed'], array_map(fn ($c) => [$c['code'], $c['name'],
                $c['current_account_type'] ?? 'NULL', $c['current_normal_balance'] ?? 'NULL', $c['proposed_account_type'] ?? 'NULL', $c['proposed_normal_balance'] ?? 'NULL'], $classification['would_fill']));
        }
        foreach ($sourceStates as $code => $action) {
            $this->line("Paying account {$code}: {$action}".($execute || $action !== 'disable' ? '' : ' (dry run)'));
        }
        if ($sources !== []) {
            $this->table(['Paying account', 'Type', 'Active', 'Linked now', 'Profile declares', 'State'], collect($sources)->map(fn ($s, $code) => [$code, $s['type'] ?? '—',
                $s['is_active'] === null ? '—' : ($s['is_active'] ? 'yes' : 'no'), $s['linked'] ?? '—', $s['account'] ?? 'leave unlinked', $s['state']])->values()->all());
        }
        $unacknowledged = array_values(array_filter($twins, fn ($t) => ! $t['acknowledged']));
        if ($unacknowledged !== []) {
            $this->line('WARNING — '.count($unacknowledged).' reference-chart account(s) sit beside the account the profile posts to. One function, two accounts: is this chart the company\'s own, or two charts in one database?');
            $shown = $this->getOutput()->isVerbose() ? $unacknowledged : array_slice($unacknowledged, 0, 5);
            foreach ($shown as $t) {
                $this->line("  - {$t['label']}: profile posts to {$t['account']}; {$t['reference']} '{$t['reference_name']}' is also here ({$t['journal_lines']} journal line(s)).");
            }
            if (count($shown) < count($unacknowledged)) {
                $this->line('  - … and '.(count($unacknowledged) - count($shown)).' more (-v lists them; --output writes them all).');
            }
        }
        foreach ($warnings as $warning) {
            $this->line("WARNING — {$warning}");
        }
        $this->line('Catalogue references resolved: '.collect($catalogue)->where('resolves', true)->count().' / '.count($catalogue)
            .'; paying-account ledger accounts resolved: '.collect($sources)->where('resolves', true)->count().' / '.count($sources));

        if ($problems !== []) {
            // Only a dry run reaches here with problems: --execute refused above.
            $this->error('BLOCKED — nothing was written, and --execute would be REFUSED until these are resolved:');
            foreach ($problems as $problem) {
                $this->line("  - {$problem}");
            }

            return self::FAILURE;
        }

        return $unresolved === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Decide, for every profile account, whether to create it, leave it, or meet
     * it with the existing account the profile declares; and collect every reason
     * the run must refuse. An entry with a reason is marked `conflict` and carries
     * its own reasons, so a dry run can still show the rest of the plan.
     *
     * @param  array<int, int>  $usage  account id => journal lines
     * @return array{0: array<string, array{spec: array, action: string, reuse?: string, problems?: list<string>}>, 1: list<string>}
     */
    private function plan(array $specs, $chart, array $profile, array $usage = [], ?string $wipPolicy = null): array
    {
        $plan = [];
        $problems = [];
        $alias = [];   // proposed code => the existing code declared in its place
        $proposed = array_map(fn ($spec) => (string) ($spec['code'] ?? ''), $specs);
        $references = $this->referencesByAccount($profile, $wipPolicy);
        $names = $chart->mapWithKeys(fn ($a) => [self::normal($a->name) => $a->code]);
        // Every account any mapping names, under either WIP policy (values only:
        // the policies share their keys, so a keyed merge would drop one of them).
        $mapped = collect((array) ($profile['functions'] ?? []))->map(fn ($f) => $f['account'] ?? null)->values()
            ->merge(collect((array) ($profile['wip_policies'] ?? []))->filter(fn ($policy) => is_array($policy))->flatMap(fn ($policy) => array_values($policy)))
            ->merge(collect((array) ($profile['catalogue'] ?? []))->map(fn ($c) => $c['account'] ?? null)->values());

        foreach ($specs as $i => $spec) {
            $code = (string) ($spec['code'] ?? '');
            $where = $code !== '' ? "Account {$code}" : "new_accounts[{$i}]";
            $known = count($problems);
            // Whatever this entry is refused for is also kept on the entry itself.
            $entry = function (array $spec, string $action, ?string $reuse = null) use (&$problems, $known): array {
                $own = array_slice($problems, $known);

                return ['spec' => $spec, 'action' => $own === [] ? $action : 'conflict'] + ($reuse !== null ? ['reuse' => $reuse] : []) + ($own === [] ? [] : ['problems' => $own]);
            };

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
            if ($code === FinanceChartProfile::WIP_POLICY_REQUIRED) {
                $problems[] = "{$where}: that code is reserved; it marks a WIP function with no approved policy and is never an account.";
            }
            if (isset($plan[$code])) {
                $problems[] = "{$where} is listed twice in the profile.";

                continue;
            }
            // A parent met by an existing account is that account.
            $parent = $spec['parent'] = isset($spec['parent']) ? ($alias[$spec['parent']] ?? $spec['parent']) : null;
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

            // Explicit reuse: the profile names the one existing account that meets this requirement.
            if (array_key_exists('reuse_existing', $spec)) {
                $target = $this->reuseTarget($where, $spec, $existing, $chart, $proposed, $alias, $problems);
                if ($target !== null) {
                    $alias[$code] = $target;
                }
                $plan[$code] = $entry($spec, 'reuse', $target ?? (is_array($spec['reuse_existing']) && is_string($spec['reuse_existing']['code'] ?? null) ? $spec['reuse_existing']['code'] : null));

                continue;
            }

            if ($existing) {
                $differences = $this->differences($existing, $spec, $chart);
                if ($differences !== []) {
                    $problems[] = "{$where} already exists with a different definition (".implode('; ', $differences).'). Existing accounts are never overwritten.';
                }
                $plan[$code] = $entry($spec, 'exists');

                continue;
            }

            // A same-named account under another code is the same account. It is
            // refused, never adopted: only an explicit reuse_existing adopts one.
            $leaf = self::normal((string) ($spec['name'] ?? ''));
            $other = $names->get($leaf);
            if ($other !== null) {
                $problems[] = "{$where} ('{$spec['name']}') duplicates existing account {$other}. Map to {$other} instead of creating it."
                    ." (Nothing is reused by name: declare reuse_existing {code: {$other}, name: {$chart->get($other)->name}} on {$code} if they are one account.)";
            }
            // The reference chart's account for the same posting function is already here.
            foreach ($references[$code] ?? [] as $reference => $label) {
                $twin = $chart->get((string) $reference);
                if ($twin && (string) $reference !== $other) {
                    $problems[] = "{$where} would be a second account for {$label}: this chart already holds the reference account {$reference} '{$twin->name}'"
                        .' ('.($usage[$twin->id] ?? 0)." journal line(s)). Declare reuse_existing for {$reference}, or settle which chart this database keeps, before creating {$code}.";
                }
            }

            $plan[$code] = $entry($spec, 'create');
        }

        return [$plan, $problems];
    }

    /**
     * The existing account a `reuse_existing` declaration names, when it is exactly
     * the account declared; otherwise null, with the reasons added to $problems.
     *
     * Two keys must agree: the code AND the name the profile wrote down. A mistyped
     * code therefore lands on an account with another name and is refused, rather
     * than quietly pointing a posting function at the wrong account.
     */
    private function reuseTarget(string $where, array $spec, ?object $existing, $chart, array $proposed, array $alias, array &$problems): ?string
    {
        $declared = $spec['reuse_existing'];
        $target = is_array($declared) ? ($declared['code'] ?? null) : null;
        $name = is_array($declared) ? ($declared['name'] ?? null) : null;
        if (! is_string($target) || blank($target) || ! is_string($name) || blank($name)) {
            $problems[] = "{$where}: reuse_existing must name the existing account by both 'code' and 'name'.";

            return null;
        }

        $before = count($problems);
        $account = $chart->get($target);
        if ($target === ($spec['code'] ?? null)) {
            $problems[] = "{$where}: reuse_existing names the account itself.";
        } elseif ($existing) {
            $problems[] = "{$where} is in the chart, and the profile also declares {$target} in its place. One requirement cannot have two accounts.";
        } elseif (in_array($target, $proposed, true)) {
            $problems[] = "{$where}: reuse_existing names {$target}, which is itself an account this profile proposes. Only an account already in the chart can be reused.";
        } elseif (! $account) {
            $problems[] = "{$where}: reuse_existing names {$target}, which is not in this chart. Nothing is reused by name or by guess.";
        } else {
            if (self::normal($account->name) !== self::normal($name)) {
                $problems[] = "{$where}: reuse_existing declares {$target} as '{$name}', but {$target} in this chart is '{$account->name}'. Refused: that is not the account the profile describes.";
            }
            if (! $account->is_active) {
                $problems[] = "{$where}: reuse_existing names {$target}, which is inactive.";
            }
            $postable = (bool) ($spec['is_postable'] ?? true);
            if ((bool) $account->is_postable !== $postable) {
                $problems[] = "{$where}: reuse_existing names {$target}, which is ".($account->is_postable ? 'postable' : 'a non-postable header').'; the requirement is '.($postable ? 'a postable account' : 'a header').'.';
            }
            foreach (['category', 'account_type', 'normal_balance'] as $column) {
                $required = $spec[$column] ?? null;
                // An existing account still awaiting classification (NULL) is not a contradiction; a different value is.
                $unset = $column !== 'category' && $account->{$column} === null;
                if ($required !== null && ! $unset && (string) $account->{$column} !== (string) $required) {
                    $problems[] = "{$where}: reuse_existing names {$target}, whose {$column} is '{$account->{$column}}'; the requirement is '{$required}'.";
                }
            }
            if (($claimant = array_search($target, $alias, true)) !== false) {
                $problems[] = "{$where}: reuse_existing names {$target}, which {$claimant} already reuses. One existing account cannot stand in for two.";
            }
        }

        return count($problems) === $before ? $target : null;
    }

    /**
     * Profile account code => the reference codes that post to it, each with what it
     * is for, under the WIP policy in force (the same reading as the map).
     *
     * @return array<string, array<string, string>>
     */
    private function referencesByAccount(array $profile, ?string $wipPolicy): array
    {
        $functions = FinanceAccountFunctions::all();
        $byFunction = array_map(fn ($f) => $f['account'] ?? null, (array) ($profile['functions'] ?? []))
            + (array) ($profile['wip_policies'][$wipPolicy] ?? []);

        $references = [];
        foreach ($byFunction as $key => $account) {
            if (filled($account) && isset($functions[$key])) {
                $references[(string) $account][$functions[$key]['code']] = "'{$key}' ({$functions[$key]['meaning']})";
            }
        }
        foreach ((array) ($profile['catalogue'] ?? []) as $reference => $entry) {
            if (filled($entry['account'] ?? null)) {
                $references[(string) $entry['account']][(string) $reference] ??= "catalogue reference {$reference} (".($entry['meaning'] ?? 'expense catalogue').')';
            }
        }

        return $references;
    }

    /**
     * Reference-chart accounts that sit beside the account the profile posts to:
     * the function has one account by the profile and another by the reference code.
     * Evidence only. Creating a new account next to one is refused in plan(); an
     * existing pair is reported, and `erp_added_accounts` is how a profile says it
     * knows about one.
     *
     * @return list<array{reference: string, reference_name: string, account: string, label: string, journal_lines: int, acknowledged: bool}>
     */
    private function referenceTwins(array $profile, $chart, array $plan, array $usage, ?string $wipPolicy): array
    {
        $alias = collect($plan)->where('action', 'reuse')->map(fn ($p) => $p['reuse'])->all();
        $acknowledged = array_map('strval', array_keys((array) ($profile['erp_added_accounts'] ?? [])));
        $twins = [];
        foreach ($this->referencesByAccount($profile, $wipPolicy) as $account => $references) {
            $account = (string) ($alias[$account] ?? $account);
            foreach ($references as $reference => $label) {
                $twin = $chart->get((string) $reference);
                if ($twin && (string) $reference !== $account) {
                    $twins["{$reference}>{$account}"] = ['reference' => (string) $reference, 'reference_name' => $twin->name, 'account' => $account, 'label' => $label,
                        'journal_lines' => (int) ($usage[$twin->id] ?? 0), 'acknowledged' => in_array((string) $reference, $acknowledged, true)];
                }
            }
        }

        return array_values($twins);
    }

    /**
     * What --classify-existing would change, without changing it: every NULL it
     * would fill (before and proposed), the accounts the profile leaves to the
     * accountant, and any active postable account still unclassified that the
     * profile does not mention at all.
     *
     * @return array{would_fill: list<array>, pending_decision: array<string, string>, not_in_profile: list<array>}
     */
    private function classificationPreview(array $profile, $chart, array $classify): array
    {
        $pending = (array) ($profile['existing_classification']['unclassified_pending_decision'] ?? []);
        $fill = [];
        foreach ($classify as $code => $columns) {
            $a = $chart->get($code);
            $fill[] = ['code' => (string) $code, 'name' => $a->name, 'category' => $a->category,
                'current_account_type' => $a->account_type, 'current_normal_balance' => $a->normal_balance,
                'proposed_account_type' => $columns['account_type'] ?? $a->account_type,
                'proposed_normal_balance' => $columns['normal_balance'] ?? $a->normal_balance,
                'fills' => array_keys($columns)];
        }
        $unmentioned = $chart->filter(fn ($a) => $a->is_active && $a->is_postable && ($a->account_type === null || $a->normal_balance === null)
            && ! isset($classify[$a->code]) && ! array_key_exists($a->code, $pending))
            ->map(fn ($a) => ['code' => $a->code, 'name' => $a->name, 'category' => $a->category, 'account_type' => $a->account_type, 'normal_balance' => $a->normal_balance])
            ->values()->all();

        return ['would_fill' => $fill,
            'pending_decision' => array_filter($pending, fn ($code) => $chart->has($code), ARRAY_FILTER_USE_KEY),
            'not_in_profile' => $unmentioned];
    }

    /**
     * Each paying account the profile names: where it is linked now, where the
     * profile says it belongs, and what stands between the two. Read only.
     *
     * @param  array<string, ?string>  $declared  source code => account code (null = leave unlinked)
     * @return array<string, array>
     */
    private function paymentSourceState(Connection $db, bool $hasSources, array $declared, \Closure $postable, array $disablePlan): array
    {
        $rows = $hasSources ? $db->table('payment_sources as ps')->leftJoin('chart_of_accounts as coa', 'coa.id', '=', 'ps.gl_account_id')
            ->get(['ps.code', 'ps.type', 'ps.is_active', 'coa.code as linked', 'coa.category as linked_category'])->keyBy('code') : collect();

        $state = [];
        foreach ($declared as $code => $account) {
            $row = $rows->get($code);
            $linked = $row?->linked;
            $expected = $row && $row->type === 'payable' ? 'liability' : 'asset';
            $state[$code] = [
                'account' => $account,
                'resolves' => $postable($account),
                'type' => $row?->type,
                'is_active' => $row ? (bool) $row->is_active : null,
                'linked' => $linked,
                'state' => match (true) {
                    ! $hasSources => 'payment_sources is not in this database',
                    ! $row => 'not present',
                    $linked !== null && $row->linked_category !== $expected => "REVIEW: linked to {$linked}, a {$row->linked_category} account; a {$row->type} source belongs on a {$expected} account",
                    $account === null && $linked !== null => "REVIEW: linked to {$linked}, but the profile leaves it unlinked until Finance decides",
                    $account === null => $row->is_active ? 'unlinked and ACTIVE; --disable-unlinked-sources would: '.($disablePlan[$code] ?? 'leave it (not listed)') : 'unlinked and disabled, awaiting Finance',
                    $linked === $account => 'linked as the profile declares'.($row->is_active ? '' : ' (inactive)'),
                    $linked === null => "not linked yet; the profile declares {$account}",
                    default => "REVIEW: linked to {$linked}; the profile declares {$account}",
                },
            ];
        }

        return $state;
    }

    /**
     * account id => journal lines, so a refusal can say whether the account it
     * names has history behind it.
     *
     * @return array<int, int>
     */
    private function journalUsage(Connection $db): array
    {
        if (! $db->getSchemaBuilder()->hasTable('journal_lines')) {
            return [];
        }

        return $db->table('journal_lines')->selectRaw('account_id, COUNT(*) AS n')->groupBy('account_id')->pluck('n', 'account_id')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * code => [column => value] for the NULL columns the profile classifies. A
     * different non-null value is a refusal: an account's classification that
     * somebody has already set is never overwritten.
     *
     * @return array{0: array<string, array<string, string>>, 1: list<string>}
     */
    private function classificationPlan(array $accounts, $chart): array
    {
        $plan = [];
        $problems = [];
        foreach ($accounts as $code => $spec) {
            $existing = $chart->get($code);
            if (! $existing) {
                $problems[] = "Classification names {$code}, which is not in this chart.";

                continue;
            }
            if (($spec['account_type'] ?? null) !== null && ! in_array($spec['account_type'], self::ACCOUNT_TYPES, true)) {
                $problems[] = "Classification of {$code}: account_type '{$spec['account_type']}' is not valid.";

                continue;
            }
            foreach (['account_type', 'normal_balance'] as $column) {
                $value = $spec[$column] ?? null;
                if ($value === null) {
                    continue;
                }
                if ($existing->{$column} === null) {
                    $plan[$code][$column] = $value;
                } elseif ((string) $existing->{$column} !== (string) $value) {
                    $problems[] = "{$code} already has {$column} '{$existing->{$column}}'; the profile says '{$value}'. An existing classification is never overwritten.";
                }
            }
        }

        return [$plan, $problems];
    }

    /**
     * code => what happens to each "disable until linked" paying account.
     *
     * @return array<string, string>
     */
    private function sourcePlan(Connection $db, array $codes): array
    {
        $users = collect($db->select(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'payment_sources'"
        ));
        $plan = [];
        foreach ($codes as $code) {
            $source = $db->table('payment_sources')->where('code', $code)->first();
            if (! $source) {
                $plan[$code] = 'not present';
            } elseif ($source->gl_account_id !== null) {
                $plan[$code] = 'linked — left active';
            } elseif (! $source->is_active) {
                $plan[$code] = 'already disabled';
            } else {
                $used = $users->first(fn ($u) => $db->table($u->t)->where($u->c, $source->id)->exists());
                $plan[$code] = $used ? "in use by {$used->t} — left active" : 'disable';
            }
        }

        return $plan;
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
