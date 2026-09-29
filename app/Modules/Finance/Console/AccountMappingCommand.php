<?php

namespace App\Modules\Finance\Console;

use App\Modules\Finance\Support\ChartAccountMap;
use App\Modules\Finance\Support\FinanceAccountFunctions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Evaluate a chart-of-accounts mapping proposal against a real chart (D3, Report 53).
 *
 * Read-only. For every posting function (FinanceAccountFunctions), every extra
 * reference code the expense catalogue names, and every paying account, it reports
 * the proposed local account, whether that account exists / is postable / is
 * active in the given chart, the classification, and whether it is approved. It
 * then derives the expense-code proposal: each catalogue code's reference account,
 * translated through the proposal.
 *
 * Nothing becomes active here. `--emit-map` prints config lines ONLY for entries
 * whose status is "approved" — an accountant's decision recorded in the proposal
 * file, never inferred.
 */
class AccountMappingCommand extends Command
{
    protected $signature = 'finance:account-mapping
        {--proposal= : Proposal JSON (default: database/finance/wng-coa-mapping-proposal.json)}
        {--connection= : Connection whose chart_of_accounts and expense_codes are evaluated (default: the app connection)}
        {--output= : Directory for the JSON/Markdown report}
        {--emit-map : Print config/finance_accounts.php map lines for APPROVED entries only}';

    protected $description = 'Evaluate the chart-of-accounts mapping proposal against a chart (read-only)';

    public function handle(): int
    {
        $path = (string) ($this->option('proposal') ?: database_path('finance/wng-coa-mapping-proposal.json'));
        $proposal = json_decode((string) @file_get_contents($path), true);
        if (! is_array($proposal)) {
            $this->error("Proposal not readable as JSON: {$path}");

            return self::FAILURE;
        }

        $db = DB::connection($this->option('connection') ?: null);
        $chart = $db->table('chart_of_accounts')->get(['id', 'code', 'name', 'category', 'is_postable', 'is_active'])->keyBy('code');
        $check = function (?string $code) use ($chart): array {
            if ($code === null) {
                return ['exists' => null, 'postable' => null, 'active' => null, 'name' => null];
            }
            $a = $chart->get($code);

            return ['exists' => (bool) $a, 'postable' => $a ? (bool) $a->is_postable : false, 'active' => $a ? (bool) $a->is_active : false, 'name' => $a?->name];
        };

        $problems = [];
        $functions = [];
        foreach (FinanceAccountFunctions::all() as $key => $f) {
            $p = $proposal['functions'][$key] ?? null;
            if ($p === null) {
                $problems[] = "Function '{$key}' ({$f['code']}) has no entry in the proposal.";

                continue;
            }
            $proposed = $p['wng_code'] ?? null;
            $candidate = $p['candidate'] ?? null;
            $state = $check($proposed ?? $candidate);
            if (($proposed || $candidate) && ! $state['exists']) {
                $problems[] = "Function '{$key}' proposes '".($proposed ?? $candidate)."', which is not in this chart.";
            }
            $functions[$key] = [
                'reference_code' => $f['code'],
                'meaning' => $f['meaning'],
                'workflows' => $f['workflows'],
                'currently_resolves_to' => ChartAccountMap::local($f['code']),
                'proposed' => $proposed,
                'candidate' => $candidate,
                'account' => $state,
                'classification' => $p['classification'] ?? null,
                'status' => $p['status'] ?? 'proposed',
                'rationale' => $p['rationale'] ?? null,
            ];
        }

        $catalogue = [];
        foreach ((array) ($proposal['catalogue_references'] ?? []) as $code => $p) {
            $local = $p['wng_code'] ?? $p['candidate'] ?? null;
            $catalogue[$code] = $p + ['account' => $check($local), 'status' => $p['status'] ?? 'proposed'];
        }

        $sources = [];
        $sourceRows = $db->getSchemaBuilder()->hasTable('payment_sources')
            ? $db->table('payment_sources')->get(['code', 'name', 'type', 'is_active', 'gl_account_id'])->keyBy('code') : collect();
        foreach ((array) ($proposal['payment_sources'] ?? []) as $code => $p) {
            $row = $sourceRows->get($code);
            $sources[$code] = $p + [
                'source' => $row ? ['name' => $row->name, 'type' => $row->type, 'active' => (bool) $row->is_active, 'linked' => $row->gl_account_id !== null] : null,
                'account' => $check($p['wng_code'] ?? null),
                'status' => $p['status'] ?? 'proposed',
            ];
        }

        // Expense-code proposal: each code's reference account, translated.
        $byReference = [];
        foreach ($functions as $key => $f) {
            $byReference[$f['reference_code']] ??= ['via' => $key, 'local' => $f['proposed'] ?? $f['candidate'], 'classification' => $f['classification']];
        }
        foreach ($catalogue as $code => $c) {
            $byReference[$code] ??= ['via' => "catalogue {$code}", 'local' => $c['wng_code'] ?? $c['candidate'] ?? null, 'classification' => $c['classification'] ?? null];
        }
        $expenseCodes = [];
        if ($db->getSchemaBuilder()->hasTable('expense_codes')) {
            foreach ($db->table('expense_codes')->orderBy('code')->get(['code', 'expense_type', 'accounting_class', 'job_id_rule', 'default_debit_gl', 'is_active']) as $ec) {
                $reference = preg_match('/^(\d{4})\b/', (string) $ec->default_debit_gl, $m) ? $m[1] : null;
                $target = $reference ? ($byReference[$reference] ?? null) : null;
                $expenseCodes[] = [
                    'code' => $ec->code,
                    'expense_type' => $ec->expense_type,
                    'accounting_class' => $ec->accounting_class,
                    'job_rule' => $ec->job_id_rule,
                    'reference_gl' => $ec->default_debit_gl,
                    'proposed_wng_account' => $target['local'] ?? null,
                    'classification' => $reference === null ? 'CAPTURE_TIME (account named in prose; chosen per transaction)' : ($target['classification'] ?? 'UNMAPPED'),
                    'active_now' => (bool) $ec->is_active,
                ];
            }
        }

        $summary = [
            'functions' => collect($functions)->countBy('classification')->all(),
            'functions_resolving_today' => collect(FinanceAccountFunctions::resolution($this->option('connection') ?: null))->where('resolved', true)->count().' / '.count($functions),
            'approved_entries' => collect([$functions, $catalogue, $sources])->flatten(1)->where('status', 'approved')->count(),
            'expense_codes' => collect($expenseCodes)->countBy('classification')->all(),
        ];
        $report = ['proposal' => $path, 'chart_accounts' => $chart->count(), 'summary' => $summary, 'problems' => $problems,
            'functions' => $functions, 'catalogue_references' => $catalogue, 'payment_sources' => $sources, 'expense_codes' => $expenseCodes];

        if ($dir = $this->option('output')) {
            @mkdir($dir, 0775, true);
            file_put_contents("{$dir}/account_mapping.json", json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->line("Report: {$dir}/account_mapping.json");
        }

        $this->table(['Function', 'Ref', 'Proposed / candidate', 'In chart', 'Classification', 'Status'],
            collect($functions)->map(fn ($f, $k) => [$k, $f['reference_code'], $f['proposed'] ?? ($f['candidate'] ? "({$f['candidate']})" : '—'),
                $f['account']['exists'] === null ? '—' : ($f['account']['exists'] && $f['account']['postable'] && $f['account']['active'] ? 'yes' : 'NO'),
                $f['classification'], $f['status']])->values()->all());
        $this->line('Summary: '.json_encode($summary));
        foreach ($problems as $problem) {
            $this->error($problem);
        }

        if ($this->option('emit-map')) {
            $approved = collect($functions)->where('status', 'approved')->filter(fn ($f) => $f['proposed'])
                ->map(fn ($f, $k) => "        '{$f['reference_code']}' => '{$f['proposed']}',   // {$k}")
                ->merge(collect($catalogue)->where('status', 'approved')->filter(fn ($c) => $c['wng_code'] ?? null)
                    ->map(fn ($c, $code) => "        '{$code}' => '{$c['wng_code']}',   // {$c['meaning']}"));
            $this->line($approved->isEmpty() ? '// No approved entries: nothing to add to the map.' : $approved->implode("\n"));
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
