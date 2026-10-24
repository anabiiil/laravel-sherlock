<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Analysis\Explain;

use Anabil\Sherlock\Analysis\Support\Sql;

/**
 * Suggests index columns for one table of a normalized query, in the order a
 * composite B-tree index serves best: equality filters, join columns, range
 * filters, then ORDER BY columns. Heuristic; returns [] when unsure.
 */
final class IndexSuggester
{
    public const MAX_COLUMNS = 3;

    /**
     * @param  string  $table  table name or alias as reported by the plan
     * @return list<string>
     */
    public function columns(string $sql, string $table): array
    {
        $aliases = Sql::tableAliases($sql);
        if ($aliases === []) {
            return [];
        }

        $real = $aliases[strtolower($table)] ?? $table;
        $names = [strtolower($real) => true];
        foreach ($aliases as $alias => $target) {
            if (strtolower($target) === strtolower($real)) {
                $names[$alias] = true;
            }
        }
        $singleTable = count(array_unique(array_map('strtolower', $aliases))) === 1;

        $belongs = static fn (?string $qualifier): bool => $qualifier === null
            ? $singleTable
            : isset($names[strtolower($qualifier)]);

        $equality = [];
        $range = [];
        $where = Sql::whereClause($sql);
        foreach ($where === null ? [] : Sql::predicates($where) as [$qualifier, $column, $isEquality]) {
            if (! $belongs($qualifier)) {
                continue;
            }
            if ($isEquality) {
                $equality[] = $column;
            } else {
                $range[] = $column;
            }
        }

        $join = [];
        if (! $singleTable) {
            foreach (Sql::joinEqualities($sql) as [$leftQualifier, $leftColumn, $rightQualifier, $rightColumn]) {
                if (isset($names[strtolower($leftQualifier)]) && ! isset($names[strtolower($rightQualifier)])) {
                    $join[] = $leftColumn;
                } elseif (isset($names[strtolower($rightQualifier)]) && ! isset($names[strtolower($leftQualifier)])) {
                    $join[] = $rightColumn;
                }
            }
        }

        $order = [];
        foreach (Sql::orderByColumns($sql) as [$qualifier, $column]) {
            if ($belongs($qualifier)) {
                $order[] = $column;
            }
        }

        $columns = [];
        foreach ([...$equality, ...$join, ...$range, ...$order] as $column) {
            if (! in_array($column, $columns, true)) {
                $columns[] = $column;
            }
        }

        return array_slice($columns, 0, self::MAX_COLUMNS);
    }

    /**
     * "orders(user_id, status)" or null.
     */
    public function suggest(?string $sql, string $table): ?string
    {
        if ($sql === null || $sql === '') {
            return null;
        }

        $columns = $this->columns($sql, $table);
        if ($columns === []) {
            return null;
        }

        $aliases = Sql::tableAliases($sql);

        return ($aliases[strtolower($table)] ?? $table).'('.implode(', ', $columns).')';
    }
}
