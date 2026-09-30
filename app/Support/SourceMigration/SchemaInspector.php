<?php

namespace App\Support\SourceMigration;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only view of one connection's schema.
 *
 * Tables come from SHOW FULL TABLES rather than information_schema.TABLES,
 * which has returned wrong table counts on this MariaDB (see the testing-DB
 * notes). Columns, keys and FKs are per-table information_schema reads, which
 * have been reliable.
 */
class SchemaInspector
{
    private ?array $tables = null;

    private array $columns = [];

    private ?array $foreignKeys = null;

    private array $primaryKeys = [];

    public function __construct(public readonly string $connectionName) {}

    public function connection(): Connection
    {
        return DB::connection($this->connectionName);
    }

    public function databaseName(): string
    {
        return (string) $this->connection()->selectOne('SELECT DATABASE() AS d')->d;
    }

    /** @return list<string> */
    public function tables(): array
    {
        if ($this->tables === null) {
            $rows = $this->connection()->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            $this->tables = collect($rows)->map(fn ($row) => (string) array_values((array) $row)[0])->sort()->values()->all();
        }

        return $this->tables;
    }

    public function hasTable(string $table): bool
    {
        return in_array($table, $this->tables(), true);
    }

    /**
     * Column definitions in ordinal order.
     *
     * @return array<string, array{type: string, nullable: bool}>
     */
    public function columns(string $table): array
    {
        if (! isset($this->columns[$table])) {
            $rows = $this->connection()->select(
                'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
                [$table],
            );
            $this->columns[$table] = collect($rows)->mapWithKeys(fn ($r) => [
                $r->COLUMN_NAME => ['type' => strtolower($r->COLUMN_TYPE), 'nullable' => $r->IS_NULLABLE === 'YES'],
            ])->all();
        }

        return $this->columns[$table];
    }

    public function hasColumn(string $table, string $column): bool
    {
        return $this->hasTable($table) && array_key_exists($column, $this->columns($table));
    }

    /** @return list<string> */
    public function primaryKey(string $table): array
    {
        if (! isset($this->primaryKeys[$table])) {
            $rows = $this->connection()->select(
                "SELECT COLUMN_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'PRIMARY'
                 ORDER BY SEQ_IN_INDEX",
                [$table],
            );
            $this->primaryKeys[$table] = array_map(fn ($r) => $r->COLUMN_NAME, $rows);
        }

        return $this->primaryKeys[$table];
    }

    /**
     * Every declared foreign key.
     *
     * @return list<array{table: string, column: string, parent: string, parent_column: string, rule: string}>
     */
    public function foreignKeys(): array
    {
        if ($this->foreignKeys === null) {
            $rows = $this->connection()->select(
                'SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE
                 FROM information_schema.KEY_COLUMN_USAGE k
                 JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                   ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
                 WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL
                 ORDER BY k.TABLE_NAME, k.COLUMN_NAME'
            );
            $this->foreignKeys = array_map(fn ($r) => [
                'table' => $r->TABLE_NAME,
                'column' => $r->COLUMN_NAME,
                'parent' => $r->REFERENCED_TABLE_NAME,
                'parent_column' => $r->REFERENCED_COLUMN_NAME,
                'rule' => $r->DELETE_RULE,
            ], $rows);
        }

        return $this->foreignKeys;
    }

    /** @return list<string> migration names recorded in this connection's ledger */
    public function ranMigrations(): array
    {
        if (! $this->hasTable('migrations')) {
            return [];
        }

        return $this->connection()->table('migrations')->orderBy('id')->pluck('migration')->all();
    }

    public function count(string $table): int
    {
        return (int) $this->connection()->table($table)->count();
    }
}
