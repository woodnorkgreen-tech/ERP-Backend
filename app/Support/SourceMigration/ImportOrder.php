<?php

namespace App\Support\SourceMigration;

/**
 * Deterministic, dependency-aware load order.
 *
 * Parents load before children along every FK between loaded tables. Cycles
 * (employees ↔ departments ↔ users, self-references) are found as strongly
 * connected components and reported explicitly; a cycle's members load together,
 * with FK checks deferred for the load session, and are verified afterwards by
 * the integrity scan. Table name is only a tie-break between tables that do not
 * depend on each other, so the order is identical on every run.
 */
class ImportOrder
{
    /** @var list<string> */
    public array $order = [];

    /** @var list<list<string>> cycles of two or more tables */
    public array $cycles = [];

    /** @var list<string> tables that reference themselves */
    public array $selfReferences = [];

    /**
     * @param  list<string>  $tables
     * @param  list<array{table: string, parent: string}>  $foreignKeys
     */
    public static function compute(array $tables, array $foreignKeys): self
    {
        $result = new self;
        $set = array_flip($tables);
        $parents = array_fill_keys($tables, []);

        foreach ($foreignKeys as $fk) {
            if (! isset($set[$fk['table']], $set[$fk['parent']])) {
                continue;
            }
            if ($fk['table'] === $fk['parent']) {
                $result->selfReferences[$fk['table']] = $fk['table'];

                continue;
            }
            $parents[$fk['table']][$fk['parent']] = true;
        }
        $result->selfReferences = array_values($result->selfReferences);
        sort($result->selfReferences);

        $components = self::stronglyConnected($tables, $parents);
        $componentOf = [];
        foreach ($components as $i => $members) {
            foreach ($members as $table) {
                $componentOf[$table] = $i;
            }
            if (count($members) > 1) {
                $result->cycles[] = $members;
            }
        }

        // Kahn's algorithm over the component graph; ties by the component's first name.
        $componentParents = array_fill_keys(array_keys($components), []);
        foreach ($parents as $child => $ps) {
            foreach (array_keys($ps) as $parent) {
                if ($componentOf[$child] !== $componentOf[$parent]) {
                    $componentParents[$componentOf[$child]][$componentOf[$parent]] = true;
                }
            }
        }

        $done = [];
        while (count($done) < count($components)) {
            $ready = [];
            foreach ($components as $i => $members) {
                if (! isset($done[$i]) && array_diff_key($componentParents[$i], $done) === []) {
                    $ready[$i] = $members[0];
                }
            }
            asort($ready, SORT_STRING);
            $next = array_key_first($ready);
            $done[$next] = true;
            array_push($result->order, ...$components[$next]);
        }

        usort($result->cycles, fn ($a, $b) => strcmp($a[0], $b[0]));

        return $result;
    }

    /**
     * Tarjan's algorithm, iterative-safe for a few hundred tables. Members of each
     * component are sorted by name.
     *
     * @param  array<string, array<string, true>>  $parents
     * @return list<list<string>>
     */
    private static function stronglyConnected(array $tables, array $parents): array
    {
        $index = 0;
        $indices = $lowlink = $onStack = [];
        $stack = $components = [];
        $sorted = $tables;
        sort($sorted);

        $visit = function (string $v) use (&$visit, &$index, &$indices, &$lowlink, &$onStack, &$stack, &$components, $parents): void {
            $indices[$v] = $lowlink[$v] = $index++;
            $stack[] = $v;
            $onStack[$v] = true;
            $edges = array_keys($parents[$v] ?? []);
            sort($edges);
            foreach ($edges as $w) {
                if (! isset($indices[$w])) {
                    $visit($w);
                    $lowlink[$v] = min($lowlink[$v], $lowlink[$w]);
                } elseif (isset($onStack[$w])) {
                    $lowlink[$v] = min($lowlink[$v], $indices[$w]);
                }
            }
            if ($lowlink[$v] === $indices[$v]) {
                $component = [];
                do {
                    $w = array_pop($stack);
                    unset($onStack[$w]);
                    $component[] = $w;
                } while ($w !== $v);
                sort($component);
                $components[] = $component;
            }
        };

        foreach ($sorted as $table) {
            if (! isset($indices[$table])) {
                $visit($table);
            }
        }

        return $components;
    }
}
