<?php

namespace App\Support\SourceMigration;

/**
 * Checks that every file referenced by a preserved row exists in a storage tree
 * (the source storage archive during rehearsal; the target's storage/app after
 * the file copy). Read-only: it lists and stats files, it never copies or moves.
 *
 * A stored value is resolved against <storage>/app/public, <storage>/app/private
 * and <storage>/app, after stripping a leading "/storage/" or "storage/". Values
 * that are absolute URLs or inline data are reported separately, not as missing.
 */
class FileReferenceVerifier
{
    public function __construct(private readonly SchemaInspector $db) {}

    /** @return list<array{table: string, column: string, kind: string, module: string, present: bool, rows: ?int}> */
    public function inventory(): array
    {
        return array_map(function ($spec) {
            [$table, $column, $kind, $module] = $spec;
            $present = $this->db->hasColumn($table, $column);

            return [
                'table' => $table, 'column' => $column, 'kind' => $kind, 'module' => $module, 'present' => $present,
                'rows' => $present ? (int) $this->db->connection()->table($table)->whereNotNull($column)->where($column, '<>', '')->count() : null,
            ];
        }, (array) config('source_migration.file_columns', []));
    }

    public function verify(string $storagePath, int $sampleLimit = 20): array
    {
        $storagePath = rtrim($storagePath, '/');
        $byTable = [];
        $byModule = [];
        $totals = ['references' => 0, 'present' => 0, 'missing' => 0, 'absolute_url' => 0, 'inline_data' => 0];

        foreach ($this->inventory() as $spec) {
            if (! $spec['present']) {
                $byTable["{$spec['table']}.{$spec['column']}"] = ['skipped' => 'column absent on this connection'];

                continue;
            }
            $key = $this->db->primaryKey($spec['table'])[0] ?? 'id';
            $stats = ['references' => 0, 'present' => 0, 'missing' => 0, 'absolute_url' => 0, 'inline_data' => 0, 'missing_samples' => []];

            $this->db->connection()->table($spec['table'])
                ->whereNotNull($spec['column'])->where($spec['column'], '<>', '')
                ->orderBy($key)->select([$key, $spec['column']])
                ->chunk(500, function ($rows) use ($spec, $key, $storagePath, $sampleLimit, &$stats) {
                    foreach ($rows as $row) {
                        foreach ($this->paths($row->{$spec['column']}, $spec['kind']) as $path) {
                            $stats['references']++;
                            $state = $this->resolve($storagePath, $path);
                            $stats[$state]++;
                            if ($state === 'missing' && count($stats['missing_samples']) < $sampleLimit) {
                                $stats['missing_samples'][] = ['id' => (string) $row->{$key}, 'path' => $path];
                            }
                        }
                    }
                });

            $byTable["{$spec['table']}.{$spec['column']}"] = $stats + ['module' => $spec['module']];
            foreach (array_keys($totals) as $k) {
                $totals[$k] += $stats[$k];
                $byModule[$spec['module']][$k] = ($byModule[$spec['module']][$k] ?? 0) + $stats[$k];
            }
        }

        ksort($byModule);

        return ['storage_path' => $storagePath, 'totals' => $totals, 'by_module' => $byModule, 'by_column' => $byTable, 'passes' => $totals['missing'] === 0];
    }

    /** @return list<string> */
    private function paths(mixed $value, string $kind): array
    {
        if ($kind !== 'json') {
            return [(string) $value];
        }
        $decoded = json_decode((string) $value, true);
        if (! is_array($decoded)) {
            return [(string) $value];
        }

        $paths = [];
        array_walk_recursive($decoded, function ($item, $key) use (&$paths) {
            if (is_string($item) && $item !== '' && (is_int($key) || in_array($key, ['path', 'file_path', 'url', 'file'], true))) {
                $paths[] = $item;
            }
        });

        return $paths;
    }

    /** @return 'present'|'missing'|'absolute_url'|'inline_data' */
    private function resolve(string $storagePath, string $value): string
    {
        if (str_starts_with($value, 'data:')) {
            return 'inline_data';
        }
        if (preg_match('#^https?://#i', $value)) {
            return 'absolute_url';
        }
        $relative = preg_replace('#^/?storage/#', '', ltrim($value, '/'));
        foreach (['app/public', 'app/private', 'app'] as $root) {
            if (is_file("{$storagePath}/{$root}/{$relative}")) {
                return 'present';
            }
        }

        return 'missing';
    }
}
