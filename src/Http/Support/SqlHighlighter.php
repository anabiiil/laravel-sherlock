<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Http\Support;

use Illuminate\Support\HtmlString;

/**
 * Light, server-side SQL display: line breaks before the main clauses and keyword /
 * placeholder spans. The SQL is HTML-escaped FIRST; only our own fixed <span> tags are
 * added afterwards, so the result is safe to print with {{ }}.
 */
final class SqlHighlighter
{
    private const CLAUSES = '/\s+(FROM|WHERE|GROUP BY|ORDER BY|HAVING|LIMIT|OFFSET|(?:LEFT |RIGHT |INNER |OUTER |CROSS )?JOIN|UNION(?: ALL)?|VALUES|SET|RETURNING)\s+/i';

    private const KEYWORDS = '/\b(SELECT|FROM|WHERE|AND|OR|NOT|IN|IS|NULL|AS|ON|JOIN|LEFT|RIGHT|INNER|OUTER|CROSS|GROUP|BY|ORDER|HAVING|LIMIT|OFFSET|INSERT|INTO|VALUES|UPDATE|SET|DELETE|DISTINCT|COUNT|SUM|AVG|MIN|MAX|EXISTS|BETWEEN|LIKE|UNION|ALL|CASE|WHEN|THEN|ELSE|END|ASC|DESC|RETURNING|WITH)\b/i';

    /** Above this length the SQL is shown as is (no clause breaks / spans) to keep rendering cheap. */
    private const MAX_HIGHLIGHT_LENGTH = 20000;

    public static function highlight(string $sql, bool $multiline = true): HtmlString
    {
        if (strlen($sql) > self::MAX_HIGHLIGHT_LENGTH) {
            return new HtmlString(e($sql));
        }

        if ($multiline && strlen($sql) > 80) {
            $sql = (string) preg_replace(self::CLAUSES, "\n$1 ", $sql);
        }

        $html = e($sql);
        $html = (string) preg_replace(self::KEYWORDS, '<span class="font-semibold text-sky-700 dark:text-sky-400">$1</span>', $html);
        $html = str_replace('?', '<span class="text-amber-600 dark:text-amber-400">?</span>', $html);

        return new HtmlString($html);
    }
}
