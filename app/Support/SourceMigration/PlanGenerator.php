<?php

namespace App\Support\SourceMigration;

use App\Modules\Finance\Support\FinanceResetBoundary;

/**
 * Builds the migration plan from config/source_migration.php (Report 50A rules
 * plus the recorded WNG decisions D1–D8). The output is a proposal: it is
 * written to disk, reviewed, and only then executed.
 */
class PlanGenerator
{
    /**
     * @param  list<string>  $tables  the staging table set (= the target's, after Stage 1)
     */
    public function generate(array $tables): MigrationPlan
    {
        $exclude = (array) config('source_migration.exclude', []);
        $pending = (array) config('source_migration.decision_pending', []);
        $skip = (array) config('source_migration.skip', []);
        $mapped = (array) config('source_migration.import_mapped', []);
        $seeded = (array) config('source_migration.replace_seeded', []);
        $protected = FinanceResetBoundary::PROTECTED;

        $entries = [];
        foreach ($tables as $table) {
            $entries[$table] = match (true) {
                isset($exclude[$table]) => ['mode' => MigrationPlan::EXCLUDE, 'reason' => $exclude[$table], 'group' => 'finance-excluded'],
                isset($pending[$table]) => ['mode' => MigrationPlan::DECISION_PENDING, 'reason' => $pending[$table], 'group' => 'D2'],
                isset($skip[$table]) => ['mode' => MigrationPlan::SKIP, 'reason' => $skip[$table], 'group' => 'skip'],
                isset($mapped[$table]) => ['mode' => MigrationPlan::IMPORT_MAPPED, 'reason' => $mapped[$table], 'group' => 'security'],
                isset($seeded[$table]) => ['mode' => MigrationPlan::REPLACE_SEEDED, 'reason' => 'Target migrations seed this table; staging rows (source + the same seeds) are authoritative', 'group' => in_array($table, $protected, true) ? 'DATA-1' : 'D1', 'natural_key' => array_values($seeded[$table])],
                in_array($table, $protected, true) => ['mode' => MigrationPlan::IMPORT, 'reason' => 'DATA-1 preserved (FinanceResetBoundary::PROTECTED)', 'group' => 'DATA-1'],
                default => ['mode' => MigrationPlan::IMPORT, 'reason' => 'D1 — non-Finance operational history, imported by default', 'group' => 'D1'],
            };
        }

        foreach ((array) config('source_migration.recorded_decisions', []) as $table => $decision) {
            if (isset($entries[$table]) && in_array($entries[$table]['mode'], MigrationPlan::LOAD_MODES, true)) {
                $entries[$table]['decision'] = $decision;
            }
        }

        return new MigrationPlan($entries, [
            'generated_at' => now()->toIso8601String(),
            'generated_from' => 'config/source_migration.php (Report 50A rules; WNG decisions D1–D8 as recorded in Report 51)',
            'decisions' => [
                'D1' => 'CONFIRMED — import all non-DATA-1 operational tables by default',
                'D2' => 'CLOSED — no historical PO/GRN/bill data to migrate (0 rows; tables stay held back)',
                'D3' => 'CONFIRMED — Option A: WNG keeps its chart; chart_of_accounts replace-seeded by code; missing accounts created by finance:complete-chart (mappings: accountant sign-off before production)',
                'D4' => 'CONFIRMED — preserve and map; migration:evidence roles',
                'D5' => 'CONFIRMED — preserve all projects; planned CostLines for active/open projects only',
                'D6' => 'CONFIRMED — not hard-coded; departments classified in the UI at cutover',
                'D7' => 'CONFIRMED — accountant input at cutover; nothing derived from excluded history',
                'D8' => 'DEFERRED — until the rehearsal passes',
            ],
            'review_status' => 'GENERATED — review before execution',
        ]);
    }
}
