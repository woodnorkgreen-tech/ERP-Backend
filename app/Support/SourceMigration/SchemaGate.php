<?php

namespace App\Support\SourceMigration;

use Illuminate\Database\Migrations\Migrator;

/**
 * Fail-closed schema checks before anything is loaded.
 *
 *  1. The target was built by the full migration chain: every migration file this
 *     codebase loads has run on the target, and none is pending.
 *  2. Stage 1 is complete: the staging ledger records every one of those migrations,
 *     so the source copy has been upgraded (backfills and seeds applied).
 *  3. Every staging table is classified by the plan, and every loaded table exists
 *     on both sides.
 *  4. Every loaded table has identical columns (name, type, nullability) on both
 *     sides. Drift is reported and the run stops; nothing is altered to fit.
 */
class SchemaGate
{
    /** @var list<string> */
    public array $problems = [];

    /** @var list<string> */
    public array $notes = [];

    /** @var list<array<string, mixed>> every column difference, classified (ColumnDrift) */
    public array $drift = [];

    public function __construct(
        private readonly SchemaInspector $source,
        private readonly SchemaInspector $target,
        private readonly MigrationPlan $plan,
        private readonly bool $acceptVerifiedNarrowing = false,
    ) {}

    public function check(): self
    {
        $this->problems = [...$this->plan->problems()];

        $expected = $this->migrationNames();
        $targetRan = array_flip($this->target->ranMigrations());
        $sourceRan = array_flip($this->source->ranMigrations());

        $targetPending = array_values(array_filter($expected, fn ($m) => ! isset($targetRan[$m])));
        if ($targetPending !== []) {
            $this->problems[] = 'Target schema incomplete: '.count($targetPending).' migration(s) not run on the target (first: '.$targetPending[0].'). Build the target with the full migration chain first.';
        }

        $stagePending = array_values(array_filter($expected, fn ($m) => ! isset($sourceRan[$m])));
        if ($stagePending !== []) {
            $this->problems[] = 'Stage 1 incomplete: '.count($stagePending).' target migration(s) are not recorded on the staging copy (first: '.$stagePending[0].'). Run migration:stage1 on the COPY first.';
        }

        foreach ($this->source->tables() as $table) {
            if ($this->plan->mode($table) === null) {
                $this->problems[] = "Staging table '{$table}' is not classified by the plan.";
            }
        }

        foreach ($this->plan->tablesIn(...MigrationPlan::LOAD_MODES) as $table) {
            if (! $this->source->hasTable($table)) {
                $this->problems[] = "Loaded table '{$table}' does not exist on the staging copy.";

                continue;
            }
            if (! $this->target->hasTable($table)) {
                $this->problems[] = "Loaded table '{$table}' does not exist on the target.";

                continue;
            }
            $this->compareColumns($table);
        }

        foreach ($this->plan->tables as $table => $entry) {
            if (! $this->source->hasTable($table)) {
                $this->notes[] = "Plan names '{$table}', which the staging copy does not have ({$entry['mode']}).";
            }
        }

        return $this;
    }

    public function passes(): bool
    {
        return $this->problems === [];
    }

    private function compareColumns(string $table): void
    {
        $source = $this->source->columns($table);
        $target = $this->target->columns($table);

        foreach (array_diff_key($source, $target) as $column => $_) {
            $this->problems[] = "Schema drift: {$table}.{$column} exists on staging but not on the target.";
        }
        foreach (array_diff_key($target, $source) as $column => $_) {
            $this->problems[] = "Schema drift: {$table}.{$column} exists on the target but not on staging.";
        }
        foreach (array_intersect_key($source, $target) as $column => $definition) {
            if ($definition === $target[$column]) {
                continue;
            }
            $drift = ColumnDrift::classify($this->source->connection(), $table, $column, $definition, $target[$column]);
            $this->drift[] = ['table' => $table, 'column' => $column] + $drift;
            $where = "{$table}.{$column} ({$drift['detail']})";

            match (true) {
                $drift['category'] === ColumnDrift::INCOMPATIBLE
                    => $this->problems[] = "Schema drift: {$where} is incompatible.",
                $drift['category'] === ColumnDrift::NARROWING && ! $drift['fits']
                    => $this->problems[] = "Schema drift: {$where} narrows and existing data does NOT fit: ".json_encode($drift['evidence']),
                $drift['category'] === ColumnDrift::NARROWING && ! $this->acceptVerifiedNarrowing
                    => $this->problems[] = "Schema drift: {$where} narrows; every existing value fits (".json_encode($drift['evidence']).'). Review, then accept with --accept-verified-narrowing.',
                default => $this->notes[] = "Drift accepted ({$drift['category']}): {$where}",
            };
        }
    }

    /** @return list<string> every migration this codebase loads, by name */
    private function migrationNames(): array
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');
        $paths = [database_path('migrations'), ...$migrator->paths()];

        return array_keys($migrator->getMigrationFiles($paths));
    }
}
