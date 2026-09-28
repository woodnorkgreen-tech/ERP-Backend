<?php

namespace App\Support\SourceMigration;

use App\Modules\Finance\Support\FinanceResetBoundary;
use JsonException;

/**
 * The reviewed migration plan: every staging table has exactly one mode.
 *
 *   import           copy every row, original IDs preserved
 *   replace-seeded   the target's migration-seeded rows are replaced by the staging rows
 *   import-mapped    rows re-keyed by name (model_has_permissions only)
 *   skip             not copied (transient, regenerated, target reference)
 *   exclude          not copied — DATA-1 Finance exclusion
 *   decision-pending not copied until WNG decides (D2, D3)
 */
class MigrationPlan
{
    public const IMPORT = 'import';
    public const REPLACE_SEEDED = 'replace-seeded';
    public const IMPORT_MAPPED = 'import-mapped';
    public const SKIP = 'skip';
    public const EXCLUDE = 'exclude';
    public const DECISION_PENDING = 'decision-pending';

    public const MODES = [self::IMPORT, self::REPLACE_SEEDED, self::IMPORT_MAPPED, self::SKIP, self::EXCLUDE, self::DECISION_PENDING];

    /** Modes that write rows into the target. */
    public const LOAD_MODES = [self::IMPORT, self::REPLACE_SEEDED, self::IMPORT_MAPPED];

    /** Tables the mapped loader knows how to re-key. */
    public const MAPPERS = ['model_has_permissions'];

    /** @param array<string, array{mode: string, reason: string, group: string, natural_key?: list<string>}> $tables */
    public function __construct(public readonly array $tables, public readonly array $meta = []) {}

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw MigrationRefused::because(["Plan file not found: {$path}. Generate it with migration:plan --generate, then review it."]);
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw MigrationRefused::because(["Plan file is not valid JSON: {$e->getMessage()}"]);
        }

        return new self((array) ($data['tables'] ?? []), (array) ($data['meta'] ?? []));
    }

    public function mode(string $table): ?string
    {
        return $this->tables[$table]['mode'] ?? null;
    }

    /** @return list<string> */
    public function tablesIn(string ...$modes): array
    {
        return array_keys(array_filter($this->tables, fn ($entry) => in_array($entry['mode'] ?? null, $modes, true)));
    }

    /**
     * Structural problems with the plan itself, independent of any database.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        if ($this->tables === []) {
            return ['Plan has no tables.'];
        }

        foreach ($this->tables as $table => $entry) {
            $mode = $entry['mode'] ?? null;
            if (! in_array($mode, self::MODES, true)) {
                $problems[] = "Table '{$table}' has invalid mode '".(is_scalar($mode) ? $mode : gettype($mode))."'.";

                continue;
            }
            if (blank($entry['reason'] ?? null)) {
                $problems[] = "Table '{$table}' has no reason recorded.";
            }
            if ($mode === self::REPLACE_SEEDED && empty($entry['natural_key'])) {
                $problems[] = "Table '{$table}' is replace-seeded but has no natural_key.";
            }
            if ($mode === self::IMPORT_MAPPED && ! in_array($table, self::MAPPERS, true)) {
                $problems[] = "Table '{$table}' is import-mapped but no mapper exists for it (only: ".implode(', ', self::MAPPERS).').';
            }
        }

        // DATA-1 fail-closed cross-check: a Finance transaction table in the Report 47
        // reset set may never be loaded. The only exception is a CONFIRM_ON_PRODUCTION
        // table (the D2 procurement documents) that WNG has decided to keep — and then
        // only with that decision recorded (checked below).
        foreach (FinanceResetBoundary::RESET_ORDER as $finance) {
            if (in_array($this->mode($finance), self::LOAD_MODES, true)
                && ! in_array($finance, FinanceResetBoundary::CONFIRM_ON_PRODUCTION, true)) {
                $problems[] = "Table '{$finance}' is DATA-1 Finance history (FinanceResetBoundary::RESET_ORDER) and must not be loaded.";
            }
        }

        // D2 and D3 stay pending until WNG decides; a plan that loads them must say so.
        foreach ([...(array) config('source_migration.d2_tables', []), ...FinanceResetBoundary::CONFIRM_ON_PRODUCTION, 'chart_of_accounts'] as $pending) {
            if (isset($this->tables[$pending]) && in_array($this->mode($pending), self::LOAD_MODES, true)
                && blank($this->tables[$pending]['decision'] ?? null)) {
                $problems[] = "Table '{$pending}' is loaded without a recorded WNG decision reference ('decision' key).";
            }
        }

        return $problems;
    }

    public function toArray(): array
    {
        $tables = $this->tables;
        ksort($tables);

        return ['meta' => $this->meta, 'tables' => $tables];
    }
}
