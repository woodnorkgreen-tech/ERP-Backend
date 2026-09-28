<?php

namespace App\Support\SourceMigration;

use Illuminate\Support\Facades\DB;

/**
 * Copies one table from the staging copy into the target with its original
 * primary keys.
 *
 * The target load session defers FK checks (cycles and self-references load in
 * dependency order but cannot always be satisfied row by row) and sets
 * NO_AUTO_VALUE_ON_ZERO so an explicit id of 0 is kept rather than renumbered.
 * Integrity is proved afterwards by the orphan scan on the target. Each table
 * loads in its own transaction; a failure rolls that table back and stops.
 */
class TableLoader
{
    private ?array $previousSession = null;

    public function __construct(
        private readonly SchemaInspector $source,
        private readonly SchemaInspector $target,
        private readonly MigrationPlan $plan,
        private readonly int $chunk = 500,
    ) {}

    public function openLoadSession(): void
    {
        $db = $this->target->connection();
        $this->previousSession = (array) $db->selectOne('SELECT @@session.foreign_key_checks AS fk, @@session.sql_mode AS mode');
        $db->statement('SET SESSION foreign_key_checks = 0');
        $db->statement("SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@session.sql_mode, ''), 'NO_AUTO_VALUE_ON_ZERO')");
    }

    public function closeLoadSession(): void
    {
        if ($this->previousSession === null) {
            return;
        }
        $db = $this->target->connection();
        $db->statement('SET SESSION foreign_key_checks = '.((int) $this->previousSession['fk']));
        $db->statement('SET SESSION sql_mode = ?', [(string) $this->previousSession['mode']]);
        $this->previousSession = null;
    }

    /**
     * Why this table cannot be loaded now, if it cannot.
     *
     * @return list<string>
     */
    public function preconditionProblems(string $table): array
    {
        $mode = $this->plan->mode($table);
        $targetCount = $this->target->count($table);

        return match ($mode) {
            MigrationPlan::IMPORT, MigrationPlan::IMPORT_MAPPED => $targetCount === 0 ? [] : [
                "Target table '{$table}' already has {$targetCount} row(s); '{$mode}' loads only into an empty table. If migrations seed it, classify it replace-seeded; if a previous run loaded it, use --resume or rebuild the target.",
            ],
            MigrationPlan::REPLACE_SEEDED => $this->seededCoverageProblems($table),
            default => ["Table '{$table}' is '{$mode}' and is never loaded."],
        };
    }

    /**
     * True when the target already holds exactly the staging rows (a previous,
     * completed run) — the --resume test.
     */
    public function alreadyLoaded(string $table): bool
    {
        if ($this->plan->mode($table) === MigrationPlan::IMPORT_MAPPED) {
            return false;
        }
        $columns = array_keys($this->source->columns($table));
        $key = LoadValidator::comparisonKey($this->source, $this->target, $table, $columns);
        $source = TableFingerprint::of($this->source, $table, $columns, $key);
        $target = TableFingerprint::of($this->target, $table, $columns, $key);

        return $source['count'] > 0 && $source === $target;
    }

    /** @return array{table: string, mode: string, rows: int, unmapped?: array<string, int>} */
    public function load(string $table): array
    {
        $mode = $this->plan->mode($table);

        return $this->target->connection()->transaction(function () use ($table, $mode) {
            if ($mode === MigrationPlan::REPLACE_SEEDED) {
                $this->target->connection()->table($table)->delete();
            }

            if ($mode === MigrationPlan::IMPORT_MAPPED) {
                return $this->loadMappedPermissions($table);
            }

            return ['table' => $table, 'mode' => $mode, 'rows' => $this->copy($table)];
        });
    }

    private function copy(string $table): int
    {
        $columns = array_keys($this->source->columns($table));
        $key = $this->source->primaryKey($table) ?: $columns;
        $source = $this->source->connection();
        $target = $this->target->connection();
        $copied = 0;

        $keyset = count($key) === 1 && str_contains($this->source->columns($table)[$key[0]]['type'] ?? '', 'int');
        $last = null;
        $offset = 0;

        do {
            $query = $source->table($table)->select($columns);
            if ($keyset) {
                if ($last !== null) {
                    $query->where($key[0], '>', $last);
                }
                $query->orderBy($key[0])->limit($this->chunk);
            } else {
                foreach ($key as $k) {
                    $query->orderBy($k);
                }
                $query->offset($offset)->limit($this->chunk);
            }

            $rows = $query->get()->map(fn ($row) => (array) $row)->all();
            if ($rows !== []) {
                $target->table($table)->insert($rows);
                $copied += count($rows);
                $last = $keyset ? end($rows)[$key[0]] : null;
                $offset += count($rows);
            }
        } while (count($rows) === $this->chunk);

        return $copied;
    }

    /**
     * Direct user grants, re-keyed by permission name. The staging permission rows
     * are never imported (they are regenerated); a grant whose name the target does
     * not define is reported, not created.
     */
    private function loadMappedPermissions(string $table): array
    {
        $sourceNames = $this->source->connection()->table('permissions')->pluck('name', 'id')->all();
        $sourceGuards = $this->source->connection()->table('permissions')->pluck('guard_name', 'id')->all();
        $targetIds = $this->target->connection()->table('permissions')->get(['id', 'name', 'guard_name'])
            ->mapWithKeys(fn ($p) => ["{$p->guard_name}|{$p->name}" => $p->id])->all();

        $columns = array_keys($this->source->columns($table));
        $rows = [];
        $unmapped = [];
        foreach ($this->source->connection()->table($table)->orderBy('permission_id')->orderBy('model_id')->get($columns) as $row) {
            $name = $sourceNames[$row->permission_id] ?? null;
            $targetId = $name === null ? null : ($targetIds[($sourceGuards[$row->permission_id] ?? 'web').'|'.$name] ?? null);
            if ($targetId === null) {
                $label = $name ?? "unknown permission id {$row->permission_id}";
                $unmapped[$label] = ($unmapped[$label] ?? 0) + 1;

                continue;
            }
            $rows[] = array_merge((array) $row, ['permission_id' => $targetId]);
        }

        foreach (array_chunk($rows, $this->chunk) as $chunk) {
            $this->target->connection()->table($table)->insertOrIgnore($chunk);
        }
        ksort($unmapped);

        return ['table' => $table, 'mode' => MigrationPlan::IMPORT_MAPPED, 'rows' => count($rows), 'unmapped' => $unmapped];
    }

    /**
     * A seeded table may be replaced only when every row the target's migrations
     * seeded is present on staging by natural key — then replacing loses nothing
     * and never overwrites master data that came from somewhere else.
     *
     * @return list<string>
     */
    private function seededCoverageProblems(string $table): array
    {
        $key = (array) ($this->plan->tables[$table]['natural_key'] ?? []);
        $sourceKeys = array_flip($this->naturalKeys($this->source, $table, $key));
        $missing = array_values(array_filter(
            $this->naturalKeys($this->target, $table, $key),
            fn ($k) => ! isset($sourceKeys[$k]),
        ));

        return $missing === [] ? [] : [sprintf(
            "Target table '%s' holds %d row(s) not present on staging by natural key (%s), e.g. [%s]. Replacing would discard them; refusing.",
            $table, count($missing), implode(',', $key), implode('], [', array_slice($missing, 0, 5)),
        )];
    }

    /** @return list<string> */
    public function naturalKeys(SchemaInspector $db, string $table, array $key): array
    {
        $rows = $db->connection()->table($table)->get(array_values(array_unique(['id', ...$key])));
        $names = in_array('parent_id', $key, true) ? $rows->pluck('name', 'id')->all() : [];

        return $rows->map(function ($row) use ($key, $names) {
            return implode('|', array_map(function ($column) use ($row, $names) {
                $value = $row->{$column};
                if ($column === 'parent_id') {
                    return $value === null ? '<root>' : ($names[$value] ?? "<missing parent {$value}>");
                }

                return $value === null ? '<null>' : mb_strtolower(trim((string) $value));
            }, $key));
        })->values()->all();
    }
}
