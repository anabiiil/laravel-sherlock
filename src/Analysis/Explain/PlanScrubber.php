<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Analysis\Explain;

/**
 * Removes literal values from a raw EXPLAIN plan.
 *
 * EXPLAIN runs with the query's (redacted) bindings, and drivers echo them back inside
 * conditions: MySQL "attached_condition" / "index_condition", PostgreSQL "Filter" /
 * "Index Cond" / "Recheck Cond", ... The raw plan ends up in the EXPLAIN cache and in the
 * issue context (dashboard, notifications, AI), so every string leaf has its literals
 * replaced with "?": quoted strings (with an optional charset introducer / N / X / B
 * prefix, and a PostgreSQL ::cast kept), hex literals and standalone numbers.
 *
 * Insights are interpreted from the unscrubbed plan first; they only carry identifiers
 * (tables, keys, columns) and row estimates. Purely numeric leaves (costs, row counts,
 * "filtered" percentages) and identifiers (`t1`.`c2`, "Order", $1) are kept.
 */
final class PlanScrubber
{
    private const MAX_DEPTH = 64;

    private const STRING_LITERAL = "/(?:\\b_[A-Za-z0-9]+\\s*|\\b[NnXxBbEe])?'(?:[^'\\\\]|\\\\.|'')*'/s";

    private const HEX_LITERAL = '/(?<![\w$`"])0x[0-9A-Fa-f]+\b/';

    private const NUMBER = '/(?<![\w$`"])\d+(?:\.\d+)?(?:[eE][+-]?\d+)?(?![\w`"])/';

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<array-key, mixed>
     */
    public static function scrub(array $raw): array
    {
        return self::walk($raw, 0);
    }

    public static function scrubString(string $value): string
    {
        if ($value === '' || is_numeric($value)) {
            return $value;
        }

        if (str_contains($value, "'")) {
            $value = (string) preg_replace(self::STRING_LITERAL, '?', $value);
        }

        if (preg_match('/\d/', $value) !== 1) {
            return $value;
        }

        $value = (string) preg_replace(self::HEX_LITERAL, '?', $value);

        return (string) preg_replace(self::NUMBER, '?', $value);
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private static function walk(array $node, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }

        foreach ($node as $key => $value) {
            if (is_string($value)) {
                $node[$key] = self::scrubString($value);
            } elseif (is_array($value)) {
                $node[$key] = self::walk($value, $depth + 1);
            }
        }

        return $node;
    }
}
