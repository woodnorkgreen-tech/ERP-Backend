<?php

namespace App\Support\SourceMigration;

/**
 * Count, key range and a deterministic content checksum for one table.
 *
 * The checksum streams every row in primary-key order and hashes the values of
 * the given columns (cast to string, NULL distinguished) — the same algorithm on
 * both connections, so equal data gives an equal hash across servers. Values are
 * never returned, logged or printed: only the digest leaves this class, which is
 * what lets users.password and salary columns be compared without exposure.
 */
class TableFingerprint
{
    /**
     * @param  list<string>|null  $columns  default: every column of the table on $db
     * @return array{count: int, min_key: ?string, max_key: ?string, checksum: string}
     */
    public static function of(SchemaInspector $db, string $table, ?array $columns = null, int $chunk = 1000): array
    {
        $columns ??= array_keys($db->columns($table));
        $key = $db->primaryKey($table) ?: $columns;
        $connection = $db->connection();

        $count = (int) $connection->table($table)->count();
        $single = count($key) === 1 ? $key[0] : null;
        $min = $single ? $connection->table($table)->min($single) : null;
        $max = $single ? $connection->table($table)->max($single) : null;

        $hash = hash_init('sha256');
        $query = $connection->table($table)->select($columns);
        foreach ($key as $k) {
            $query->orderBy($k);
        }

        $offset = 0;
        do {
            $rows = (clone $query)->offset($offset)->limit($chunk)->get();
            foreach ($rows as $row) {
                foreach ($columns as $column) {
                    $value = $row->{$column};
                    hash_update($hash, $value === null ? "\0N\0" : "\0V".strlen((string) $value).':'.$value);
                }
                hash_update($hash, "\0R\0");
            }
            $offset += $chunk;
        } while ($rows->count() === $chunk);

        return [
            'count' => $count,
            'min_key' => $min === null ? null : (string) $min,
            'max_key' => $max === null ? null : (string) $max,
            'checksum' => hash_final($hash),
        ];
    }
}
