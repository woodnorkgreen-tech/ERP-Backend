<?php

namespace App\Support\SourceMigration;

/**
 * Post-load reconciliation (Report 51 §11): per loaded table, staging and target
 * must agree on count, key range and content checksum; the target must have no
 * orphan that the staging copy did not already have; excluded data must be absent.
 */
class LoadValidator
{
    public function __construct(
        private readonly SchemaInspector $source,
        private readonly SchemaInspector $target,
        private readonly MigrationPlan $plan,
    ) {}

    /**
     * @param  list<string>  $tables  tables to reconcile (default: every loaded table except import-mapped)
     * @param  list<array<string, mixed>>  $preImportOrphans  findings from the staging scan
     */
    public function validate(?array $tables = null, array $preImportOrphans = []): array
    {
        $tables ??= $this->plan->tablesIn(MigrationPlan::IMPORT, MigrationPlan::REPLACE_SEEDED);
        $reconciliation = [];
        $mismatches = [];

        foreach ($tables as $table) {
            if (! $this->source->hasTable($table) || ! $this->target->hasTable($table)) {
                continue;
            }
            $columns = array_keys($this->source->columns($table));
            $s = TableFingerprint::of($this->source, $table, $columns);
            $t = TableFingerprint::of($this->target, $table, $columns);
            $match = $s === $t;
            $reconciliation[$table] = [
                'staging_count' => $s['count'], 'target_count' => $t['count'],
                'staging_key_range' => [$s['min_key'], $s['max_key']], 'target_key_range' => [$t['min_key'], $t['max_key']],
                'checksum_match' => $s['checksum'] === $t['checksum'],
                'match' => $match,
            ];
            if (! $match) {
                $mismatches[] = $table;
            }
        }

        $post = (new OrphanScanner($this->target, $this->target, $this->plan))->scan();
        $pre = collect($preImportOrphans)->mapWithKeys(fn ($f) => ["{$f['table']}.{$f['column']}" => $f['orphans'] ?? 0])->all();
        $introduced = array_values(array_filter($post, fn ($f) => ($f['orphans'] ?? 0) > ($pre["{$f['table']}.{$f['column']}"] ?? 0)));

        $reference = (array) config('source_migration.reference_counts.counts', []);
        $headline = [];
        foreach ($reference as $table => $observed) {
            $headline[$table] = [
                'operator_observed_'.config('source_migration.reference_counts.observed_on') => $observed,
                'staging' => $this->source->hasTable($table) ? $this->source->count($table) : null,
                'target' => $this->target->hasTable($table) ? $this->target->count($table) : null,
            ];
            $headline[$table]['staging_equals_target'] = $headline[$table]['staging'] === $headline[$table]['target'];
            $headline[$table]['note'] = $headline[$table]['staging'] === $observed
                ? 'matches the operator observation'
                : 'differs from the operator observation — the source copy is the authority; explain the difference in the rehearsal report';
        }

        $exclusions = EvidenceReports::exclusions($this->source, $this->target, $this->plan);

        return [
            'tables_reconciled' => count($reconciliation),
            'mismatched_tables' => $mismatches,
            'reconciliation' => $reconciliation,
            'headline_counts' => $headline,
            'orphans_on_target' => $post,
            'orphans_introduced_by_load' => $introduced,
            'exclusions' => $exclusions,
            'passes' => $mismatches === [] && $introduced === [] && $exclusions['passes']
                && collect($headline)->every(fn ($h) => $h['staging_equals_target']),
        ];
    }
}
