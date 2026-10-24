<?php

declare(strict_types=1);

namespace Anabil\Tracing\Support;

use Anabil\Tracing\Contracts\QueryNormalizer;

/**
 * Normalizes SQL for fingerprinting and display:
 *  - string / numeric / hex literals and driver placeholders ($1, :name) become "?"
 *  - comments removed, whitespace collapsed, comma / parenthesis spacing unified
 *  - IN (?, ?, ?) becomes IN (?+), multi-row VALUES (...), (...) collapsed to one tuple
 *
 * Keyword case is preserved for display; Fingerprinter lowercases before hashing.
 * All regexes are constants compiled once by PCRE's cache, applied in a single
 * preg_replace() call, and results are memoized per raw SQL (bounded).
 */
final class SqlQueryNormalizer implements QueryNormalizer
{
    private const CACHE_LIMIT = 1000;

    private const CACHEABLE_LENGTH = 8192;

    /** Ordered: literals first (so comment markers inside strings are safe), then structure. */
    private const PATTERNS = [
        // single-quoted strings, optional N/E/X/B prefix, '' and \' escapes
        '/(?<![\w])[nNeExXbB]?\'(?:[^\'\\\\]|\\\\.|\'\')*\'/s',
        // block and line comments
        '/\/\*.*?\*\//s',
        '/--[^\n]*/',
        // PostgreSQL positional placeholders
        '/\$\d+\b/',
        // named placeholders (not :: casts)
        '/(?<![:\w]):[A-Za-z_]\w*/',
        // hex literals
        '/\b0[xX][0-9a-fA-F]+\b/',
        // numeric literals not part of identifiers
        '/(?<![\w.$?])\d+(?:\.\d+)?(?:[eE][+-]?\d+)?\b/',
        // whitespace
        '/\s+/',
        // spacing around commas and parentheses
        '/\s*,\s*/',
        '/\(\s+/',
        '/\s+\)/',
        // IN lists
        '/\bIN\s*\(\?(?:, \?)*\)/i',
        // multi-row VALUES
        '/\bVALUES\s*(\([^()]*\))(?:, \([^()]*\))+/i',
    ];

    private const REPLACEMENTS = [
        '?',
        ' ',
        ' ',
        '?',
        '?',
        '?',
        '?',
        ' ',
        ', ',
        '(',
        ')',
        'IN (?+)',
        'VALUES $1',
    ];

    /** @var array<string, string> */
    private array $cache = [];

    public function normalize(string $sql): string
    {
        if (isset($this->cache[$sql])) {
            return $this->cache[$sql];
        }

        $normalized = preg_replace(self::PATTERNS, self::REPLACEMENTS, $sql);
        $normalized = rtrim(trim($normalized ?? $sql), "; \t\n\r");

        if (strlen($sql) <= self::CACHEABLE_LENGTH) {
            if (count($this->cache) >= self::CACHE_LIMIT) {
                $this->cache = [];
            }
            $this->cache[$sql] = $normalized;
        }

        return $normalized;
    }
}
