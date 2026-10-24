<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Analysis\Explain;

use Anabil\Sherlock\Analysis\Support\Sql;
use Anabil\Sherlock\Data\QueryRecord;

/**
 * Safety rules for EXPLAIN. Conservative by design: anything not clearly a plain,
 * single, read-only SELECT is rejected (a skipped plan is harmless, a side effect is not).
 *
 *  - only SELECT (or a WITH ... SELECT without data-modifying statements)
 *  - no ";" anywhere (no multiple statements), no INTO, no locking clauses
 *  - no side-effect / sleep / lock / file functions
 *  - bindings captured for every "?" (stored ones, or the transient EXPLAIN-only ones of
 *    lightweight capture), or no placeholder at all: values are never invented
 */
final class ExplainGuard
{
    public const MAX_SQL_LENGTH = 20000;

    private const FORBIDDEN = '/\b(?:insert|update|delete|replace|merge|upsert|truncate|drop|alter|create|rename|grant|revoke|'
        .'call|exec|execute|do|handler|lock|unlock|into|outfile|dumpfile|load_file|load_extension|sleep|benchmark|pg_sleep\w*|'
        .'get_lock|release_lock|release_all_locks|pg_advisory\w*|pg_try_advisory\w*|nextval|setval|set_config|lo_import|lo_export|'
        .'pg_read_file|pg_read_binary_file|pg_ls_dir|dblink\w*|pg_terminate_backend|pg_cancel_backend|attach|detach|pragma|vacuum|analyze|copy|share|nowait|skip\s+locked)\b/i';

    /**
     * Whether $sql is a single read-only SELECT.
     */
    public static function isSafe(string $sql): bool
    {
        $sql = trim($sql);
        if ($sql === '' || strlen($sql) > self::MAX_SQL_LENGTH || str_contains($sql, ';')) {
            return false;
        }

        $verb = Sql::verb($sql);
        if ($verb !== 'select' && $verb !== 'with') {
            return false;
        }

        // Keywords inside string literals are data, not SQL; comments could hide anything.
        $code = Sql::stripLiterals($sql);
        if (str_contains($code, '--') || str_contains($code, '/*') || str_contains($code, '#')) {
            return false;
        }

        return preg_match(self::FORBIDDEN, $code) !== 1;
    }

    /**
     * Bindings are available for every positional placeholder, or there is none.
     */
    public static function hasUsableBindings(QueryRecord $query): bool
    {
        $bindings = $query->bindingsForExplain();
        if ($bindings === null) {
            return ! Sql::hasPlaceholders($query->sql);
        }

        $positional = Sql::positionalPlaceholders($query->sql);
        if ($positional > 0) {
            return array_is_list($bindings) && count($bindings) >= $positional;
        }

        return true;
    }

    public static function isEligible(QueryRecord $query): bool
    {
        return self::isSafe($query->sql) && self::hasUsableBindings($query);
    }
}
