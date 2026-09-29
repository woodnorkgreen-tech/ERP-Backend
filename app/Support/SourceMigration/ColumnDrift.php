<?php

namespace App\Support\SourceMigration;

use Illuminate\Database\Connection;

/**
 * Classifies a difference between a staging column and the target column it loads
 * into, and — for a narrowing — proves from the staging data whether every value fits.
 *
 *   equivalent  the same type spelled differently (uuid ≡ char(36), int display widths,
 *               json ≡ longtext). Loads unchanged.
 *   widening    the target holds every value the staging type can (longer varchar,
 *               NOT NULL → NULL, enum values added, bigger integer/text type).
 *   narrowing   the target is stricter (shorter varchar, NULL → NOT NULL, enum values
 *               removed). Checked against the actual staging data: `fits` says whether
 *               every existing value would load unchanged.
 *   incompatible anything else — never loaded.
 *
 * Seen for real in the local dress rehearsal: databases created before the 191-character
 * default string length carry varchar(255) where the migration chain now builds
 * varchar(191), and MariaDB's native uuid type where the chain builds char(36).
 */
class ColumnDrift
{
    public const EQUIVALENT = 'equivalent';
    public const WIDENING = 'widening';
    public const NARROWING = 'narrowing';
    public const INCOMPATIBLE = 'incompatible';

    private const INT_RANK = ['tinyint' => 1, 'smallint' => 2, 'mediumint' => 3, 'int' => 4, 'bigint' => 5];
    private const TEXT_BYTES = ['tinytext' => 255, 'text' => 65535, 'mediumtext' => 16777215, 'longtext' => 4294967295];

    /**
     * @param  array{type: string, nullable: bool}  $staging
     * @param  array{type: string, nullable: bool}  $target
     * @return array{category: string, detail: string, fits?: bool, evidence?: array<string, mixed>}
     */
    public static function classify(Connection $data, string $table, string $column, array $staging, array $target): array
    {
        $s = self::normalise($staging['type']);
        $t = self::normalise($target['type']);
        $checks = [];

        $typeCategory = $s === $t ? self::EQUIVALENT : self::compareTypes($s, $t, $checks);
        $nullCategory = match (true) {
            $staging['nullable'] === $target['nullable'] => self::EQUIVALENT,
            $target['nullable'] => self::WIDENING,
            default => self::NARROWING,
        };
        if ($nullCategory === self::NARROWING) {
            $checks['no_nulls'] = true;
        }

        $order = [self::EQUIVALENT => 0, self::WIDENING => 1, self::NARROWING => 2, self::INCOMPATIBLE => 3];
        $category = $order[$typeCategory] >= $order[$nullCategory] ? $typeCategory : $nullCategory;
        $detail = sprintf('%s%s → %s%s', $staging['type'], $staging['nullable'] ? ' NULL' : ' NOT NULL', $target['type'], $target['nullable'] ? ' NULL' : ' NOT NULL');

        if ($category !== self::NARROWING) {
            return ['category' => $category, 'detail' => $detail];
        }

        $evidence = [];
        $fits = true;
        $q = fn () => $data->table($table);
        if (isset($checks['max_length'])) {
            $longest = (int) $q()->selectRaw("COALESCE(MAX(CHAR_LENGTH(`{$column}`)), 0) AS m")->value('m');
            $evidence['longest_value'] = $longest;
            $evidence['target_length'] = $checks['max_length'];
            $fits = $fits && $longest <= $checks['max_length'];
        }
        if (isset($checks['no_nulls'])) {
            $nulls = (int) $q()->whereNull($column)->count();
            $evidence['null_rows'] = $nulls;
            $fits = $fits && $nulls === 0;
        }
        if (isset($checks['enum_values'])) {
            $used = $q()->whereNotNull($column)->distinct()->orderBy($column)->pluck($column)->map(fn ($v) => (string) $v)->all();
            $outside = array_values(array_diff($used, $checks['enum_values']));
            $evidence['values_outside_target_enum'] = $outside;
            $evidence['rows_outside_target_enum'] = $outside === [] ? 0 : (int) $q()->whereIn($column, $outside)->count();
            $fits = $fits && $outside === [];
        }

        return ['category' => $category, 'detail' => $detail, 'fits' => $fits, 'evidence' => $evidence];
    }

    public static function normalise(string $type): string
    {
        $type = strtolower(trim($type));
        $type = preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', $type);
        // tinyint(1) keeps meaning as a boolean in both engines; display width is not storage.
        return match ($type) {
            'uuid' => 'char(36)',
            'json' => 'longtext',
            default => $type,
        };
    }

    private static function compareTypes(string $s, string $t, array &$checks): string
    {
        // Strings: char/varchar(n)
        if (preg_match('/^(var)?char\((\d+)\)$/', $s, $a) && preg_match('/^(var)?char\((\d+)\)$/', $t, $b)) {
            if ((int) $b[2] >= (int) $a[2]) {
                return self::WIDENING;
            }
            $checks['max_length'] = (int) $b[2];

            return self::NARROWING;
        }
        // String into text
        if (preg_match('/^(var)?char\((\d+)\)$/', $s, $a) && isset(self::TEXT_BYTES[$t])) {
            return self::TEXT_BYTES[$t] >= (int) $a[2] * 4 ? self::WIDENING : self::INCOMPATIBLE;
        }
        // Text family
        if (isset(self::TEXT_BYTES[$s], self::TEXT_BYTES[$t])) {
            return self::TEXT_BYTES[$t] >= self::TEXT_BYTES[$s] ? self::WIDENING : self::INCOMPATIBLE;
        }
        // Integers (same signedness)
        if (preg_match('/^(\w+)( unsigned)?$/', $s, $a) && preg_match('/^(\w+)( unsigned)?$/', $t, $b)
            && isset(self::INT_RANK[$a[1]], self::INT_RANK[$b[1]]) && ($a[2] ?? '') === ($b[2] ?? '')) {
            return self::INT_RANK[$b[1]] >= self::INT_RANK[$a[1]] ? self::WIDENING : self::INCOMPATIBLE;
        }
        // Decimals
        if (preg_match('/^decimal\((\d+),(\d+)\)/', $s, $a) && preg_match('/^decimal\((\d+),(\d+)\)/', $t, $b)) {
            return ((int) $b[2] >= (int) $a[2] && ((int) $b[1] - (int) $b[2]) >= ((int) $a[1] - (int) $a[2])) ? self::WIDENING : self::INCOMPATIBLE;
        }
        // Enums
        $sEnum = self::enumValues($s);
        $tEnum = self::enumValues($t);
        if ($sEnum !== null && $tEnum !== null) {
            if (array_diff($sEnum, $tEnum) === []) {
                return self::WIDENING;
            }
            $checks['enum_values'] = $tEnum;

            return self::NARROWING;
        }
        if ($sEnum !== null && preg_match('/^varchar\((\d+)\)$/', $t, $b)) {
            return max(array_map('mb_strlen', $sEnum)) <= (int) $b[1] ? self::WIDENING : self::INCOMPATIBLE;
        }

        return self::INCOMPATIBLE;
    }

    /** @return list<string>|null */
    private static function enumValues(string $type): ?array
    {
        if (! preg_match('/^enum\((.*)\)$/', $type, $m)) {
            return null;
        }
        preg_match_all("/'((?:[^']|'')*)'/", $m[1], $values);

        return array_map(fn ($v) => str_replace("''", "'", $v), $values[1]);
    }
}
