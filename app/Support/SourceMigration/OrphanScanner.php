<?php

namespace App\Support\SourceMigration;

use App\Modules\Finance\Support\FinanceResetBoundary;
use Illuminate\Support\Facades\DB;

/**
 * Finds references that point at nothing, before a load (on staging) and after it
 * (on the target). Covers every FK the target schema declares and the integer
 * references the database does not enforce (config: unenforced_references),
 * including polymorphic ones. Reports only — it never drops a row.
 */
class OrphanScanner
{
    public const HIGH = 'HIGH';
    public const MEDIUM = 'MEDIUM';
    public const LOW = 'LOW';

    /** Parents whose loss disconnects the preserved graph. */
    private const CORE_PARENTS = ['project_enquiries', 'projects', 'enquiry_tasks', 'employees', 'users', 'clients', 'departments', 'task_budget_data'];

    public function __construct(
        private readonly SchemaInspector $data,
        private readonly SchemaInspector $schema,
        private readonly MigrationPlan $plan,
    ) {}

    /**
     * The relationships to check for the plan's loaded tables.
     *
     * @return list<array{table: string, column: string, parent: string, parent_column: string, enforced: bool, type_column?: string, type?: string}>
     */
    public function relationships(): array
    {
        $loaded = array_flip($this->plan->tablesIn(...MigrationPlan::LOAD_MODES));
        $relations = [];

        foreach ($this->schema->foreignKeys() as $fk) {
            if (isset($loaded[$fk['table']])) {
                $relations["{$fk['table']}.{$fk['column']}"] = [
                    'table' => $fk['table'], 'column' => $fk['column'],
                    'parent' => $fk['parent'], 'parent_column' => $fk['parent_column'], 'enforced' => true,
                ];
            }
        }

        foreach ((array) config('source_migration.unenforced_references', []) as $ref => $parent) {
            [$table, $column] = explode('.', $ref, 2);
            if (! isset($loaded[$table]) || isset($relations[$ref])) {
                continue;
            }
            $relations[$ref] = is_array($parent)
                ? ['table' => $table, 'column' => $column, 'parent' => $parent['table'], 'parent_column' => 'id', 'enforced' => false,
                    'type_column' => $parent['type_column'], 'type' => $parent['type']]
                : ['table' => $table, 'column' => $column, 'parent' => $parent, 'parent_column' => 'id', 'enforced' => false];
        }

        ksort($relations);

        return array_values($relations);
    }

    /**
     * @return list<array<string, mixed>> one finding per broken relationship
     */
    public function scan(): array
    {
        $findings = [];
        foreach ($this->relationships() as $rel) {
            $finding = $this->check($rel);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /** @return array<string, mixed>|null */
    private function check(array $rel): ?array
    {
        $db = $this->data;
        if (! $db->hasColumn($rel['table'], $rel['column'])) {
            return null;
        }
        if (! $db->hasTable($rel['parent'])) {
            return $this->finding($rel, null, [], [], 'parent table absent on this connection');
        }

        // Composite keys (pivot tables) are sampled as the whole key, e.g. "3|App\Models\User|41".
        $keyColumns = $db->primaryKey($rel['table']) ?: [$rel['column']];
        $childKey = count($keyColumns) === 1
            ? $keyColumns[0]
            : DB::raw('CONCAT_WS(\'|\', '.implode(', ', array_map(fn ($c) => "c.`{$c}`", $keyColumns)).')');
        $query = $db->connection()->table("{$rel['table']} as c")
            ->leftJoin("{$rel['parent']} as p", "p.{$rel['parent_column']}", '=', "c.{$rel['column']}")
            ->whereNotNull("c.{$rel['column']}")
            ->whereNull("p.{$rel['parent_column']}");
        if (isset($rel['type_column'])) {
            $query->where("c.{$rel['type_column']}", $rel['type']);
        }

        $count = (clone $query)->count();
        if ($count === 0) {
            return null;
        }

        $childIds = is_string($childKey)
            ? (clone $query)->orderBy("c.{$childKey}")->limit(5)->pluck("c.{$childKey}")->map(fn ($v) => (string) $v)->all()
            : (clone $query)->selectRaw($childKey->getValue(DB::connection()->getQueryGrammar()).' AS k')->orderBy('k')->limit(5)->pluck('k')->map(fn ($v) => (string) $v)->all();
        $missing = (clone $query)->distinct()->orderBy("c.{$rel['column']}")->limit(5)->pluck("c.{$rel['column']}")->map(fn ($v) => (string) $v)->all();

        return $this->finding($rel, $count, $childIds, $missing);
    }

    private function finding(array $rel, ?int $count, array $childIds, array $missing, ?string $note = null): array
    {
        $parentMode = $this->plan->mode($rel['parent']);
        $parentLoaded = in_array($parentMode, MigrationPlan::LOAD_MODES, true);

        $severity = match (true) {
            in_array($parentMode, [MigrationPlan::EXCLUDE, MigrationPlan::DECISION_PENDING], true) => self::HIGH,
            in_array($rel['parent'], self::CORE_PARENTS, true) => self::HIGH,
            in_array($rel['table'], FinanceResetBoundary::PROTECTED, true) => self::HIGH,
            $parentLoaded => self::MEDIUM,
            default => self::LOW,
        };

        return array_filter([
            'table' => $rel['table'],
            'column' => $rel['column'],
            'parent' => $rel['parent'],
            'enforced_by_fk' => $rel['enforced'],
            'parent_mode' => $parentMode ?? 'not in plan',
            'orphans' => $count,
            'sample_child_ids' => $childIds,
            'sample_missing_parent_ids' => $missing,
            'severity' => $severity,
            'note' => $note,
        ], fn ($v) => $v !== null);
    }
}
