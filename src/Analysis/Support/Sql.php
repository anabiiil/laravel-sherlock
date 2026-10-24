<?php

declare(strict_types=1);

namespace Anabil\Tracing\Analysis\Support;

/**
 * Cheap, regex-based SQL inspection used for titles, recommendations, index
 * suggestions and EXPLAIN safety. Works on raw or normalized SQL with MySQL
 * backticks, ANSI double quotes or [brackets]. Never a full parser: every helper
 * degrades to null / [] when unsure.
 */
final class Sql
{
    /** One identifier, optionally quoted. */
    public const IDENT = '(?:`[^`]+`|"[^"]+"|\[[^\]]+\]|[\w$]+)';

    /** Words that can follow a table reference but are never an alias. */
    private const NOT_ALIASES = [
        'where', 'on', 'using', 'join', 'inner', 'left', 'right', 'full', 'outer', 'cross', 'natural',
        'straight_join', 'group', 'order', 'limit', 'offset', 'having', 'union', 'for', 'lock', 'window',
        'set', 'values', 'select', 'as', 'force', 'use', 'ignore', 'partition', 'fetch', 'returning', 'except',
        'intersect', 'tablesample', 'only', 'lateral',
    ];

    /**
     * First table referenced (FROM / UPDATE / INSERT INTO / DELETE FROM), unquoted, without schema.
     */
    public static function primaryTable(string $sql): ?string
    {
        $pattern = '/\b(?:from|update|into)\s+('.self::IDENT.'(?:\s*\.\s*'.self::IDENT.')?)/i';
        if (preg_match($pattern, $sql, $m) !== 1) {
            return null;
        }

        return self::unquote($m[1]);
    }

    /**
     * Table references of FROM / JOIN clauses: lowercase alias-or-name => real table name.
     * Every table maps to itself as well.
     *
     * @return array<string, string>
     */
    public static function tableAliases(string $sql): array
    {
        $pattern = '/\b(?:from|join)\s+('.self::IDENT.'(?:\s*\.\s*'.self::IDENT.')?)(?:\s+(?:as\s+)?('.self::IDENT.'))?/i';
        if (preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $aliases = [];
        foreach ($matches as $m) {
            $table = self::unquote($m[1]);
            $aliases[strtolower($table)] = $table;

            $alias = isset($m[2]) && $m[2] !== '' ? self::unquote($m[2]) : null;
            if ($alias !== null && ! in_array(strtolower($alias), self::NOT_ALIASES, true)) {
                $aliases[strtolower($alias)] = $table;
            }
        }

        return $aliases;
    }

    /**
     * Strip quotes and schema / table qualifiers: `db`.`orders` -> orders.
     */
    public static function unquote(string $identifier): string
    {
        $parts = preg_split('/\s*\.\s*(?=(?:`|"|\[|[\w$]))/', trim($identifier)) ?: [$identifier];
        $last = (string) end($parts);

        return trim($last, '`"[] ');
    }

    /**
     * Replace single-quoted string literals by '' (so keywords, ";" or "?" inside them are ignored).
     */
    public static function stripLiterals(string $sql): string
    {
        if (! str_contains($sql, "'")) {
            return $sql;
        }

        return (string) preg_replace('/\'(?:[^\'\\\\]|\\\\.|\'\')*\'/s', "''", $sql);
    }

    /** Number of positional "?" placeholders outside string literals (?? / ?| / ?& operators excluded). */
    public static function positionalPlaceholders(string $sql): int
    {
        if (! str_contains($sql, '?')) {
            return 0;
        }

        return (int) preg_match_all('/(?<![?\w])\?(?![?|&\w])/', self::stripLiterals($sql));
    }

    /** Whether the SQL contains any bind placeholder: "?", ":name" (not "::cast") or "$1". */
    public static function hasPlaceholders(string $sql): bool
    {
        $sql = self::stripLiterals($sql);

        return self::positionalPlaceholders($sql) > 0
            || preg_match('/(?<![:\w]):[A-Za-z_]\w*|\$\d+/', $sql) === 1;
    }

    /** Leading keyword, lowercase ("select", "with", "update", ...). */
    public static function verb(string $sql): string
    {
        return preg_match('/^[\s(]*([A-Za-z]+)/', $sql, $m) === 1 ? strtolower($m[1]) : '';
    }

    /**
     * The WHERE clause body (up to GROUP BY / ORDER BY / LIMIT / ...), or null.
     */
    public static function whereClause(string $sql): ?string
    {
        if (preg_match('/\bwhere\b(.*?)(?:\bgroup\s+by\b|\border\s+by\b|\blimit\b|\bhaving\b|\bunion\b|\bfor\s+update\b|\bwindow\b|\breturning\b|$)/is', $sql, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /**
     * Columns of the ORDER BY clause as [qualifier|null, column] pairs.
     *
     * @return list<array{0: string|null, 1: string}>
     */
    public static function orderByColumns(string $sql): array
    {
        if (preg_match('/\border\s+by\s+(.*?)(?:\blimit\b|\boffset\b|\bfor\b|\bfetch\b|\)|$)/is', $sql, $m) !== 1) {
            return [];
        }

        $columns = [];
        foreach (explode(',', $m[1]) as $part) {
            $part = trim((string) preg_replace('/\s+(?:asc|desc|nulls\s+(?:first|last))\b.*$/is', '', trim($part)));
            if (preg_match('/^(?:('.self::IDENT.')\s*\.\s*)?('.self::IDENT.')$/', $part, $c) === 1) {
                $columns[] = [$c[1] !== '' ? self::unquote($c[1]) : null, self::unquote($c[2])];
            }
        }

        return $columns;
    }

    /**
     * Predicates "col op ?" of a clause: [qualifier|null, column, isEquality].
     *
     * @return list<array{0: string|null, 1: string, 2: bool}>
     */
    public static function predicates(string $clause): array
    {
        $pattern = '/(?:('.self::IDENT.')\s*\.\s*)?('.self::IDENT.')\s*'
            .'(=|<=>|<=|>=|<|>|\bnot\s+in\b|\bin\b|\bnot\s+like\b|\blike\b|\bilike\b|\bbetween\b|\bis\b)\s*'
            .'(?=\?|\(\s*\?|null\b|not\s+null\b)/i';

        if (preg_match_all($pattern, $clause, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $predicates = [];
        foreach ($matches as $m) {
            $column = self::unquote($m[2]);
            $operator = strtolower((string) preg_replace('/\s+/', ' ', $m[3]));
            if (str_starts_with($operator, 'not ') || is_numeric($column) || in_array(strtolower($column), ['and', 'or', 'not'], true)) {
                continue;
            }
            $predicates[] = [
                $m[1] !== '' ? self::unquote($m[1]) : null,
                $column,
                in_array($operator, ['=', '<=>', 'in', 'is'], true),
            ];
        }

        return $predicates;
    }

    /**
     * Column equalities "a.x = b.y" (JOIN conditions) as [qualifierA, columnA, qualifierB, columnB].
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function joinEqualities(string $sql): array
    {
        $col = '('.self::IDENT.')\s*\.\s*('.self::IDENT.')';
        if (preg_match_all('/'.$col.'\s*=\s*'.$col.'/', $sql, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $pairs = [];
        foreach ($matches as $m) {
            $pairs[] = [self::unquote($m[1]), self::unquote($m[2]), self::unquote($m[3]), self::unquote($m[4])];
        }

        return $pairs;
    }
}
