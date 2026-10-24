<?php

declare(strict_types=1);

namespace Anabil\Tracing\Analysis\Analyzers;

use Anabil\Tracing\Analysis\Support\Describe;
use Anabil\Tracing\Analysis\Support\ResolvesQueries;
use Anabil\Tracing\Analysis\Support\Sql;
use Anabil\Tracing\Contracts\Analyzer;
use Anabil\Tracing\Contracts\Fingerprinter;
use Anabil\Tracing\Data\Finding;
use Anabil\Tracing\Data\QueryRecord;
use Anabil\Tracing\Data\TraceData;
use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Enums\Severity;

/**
 * A query taking >= warningMs (critical >= criticalMs). One finding per query
 * fingerprint + source location; repeated slow executions are aggregated.
 */
final class SlowQueryAnalyzer implements Analyzer
{
    use ResolvesQueries;

    public function __construct(
        private readonly Fingerprinter $fingerprinter,
        private readonly float $warningMs = 100,
        private readonly float $criticalMs = 1000,
    ) {}

    public function analyze(TraceData $trace): array
    {
        /** @var array<string, array{query: QueryRecord, fingerprint: string, count: int, total: float}> $groups */
        $groups = [];

        foreach ($trace->queries as $query) {
            if ($query->timeMs < $this->warningMs) {
                continue;
            }

            $fingerprint = $this->fingerprintOf($query);
            $key = $fingerprint."\0".($query->location?->toString() ?? '');

            if (! isset($groups[$key])) {
                $groups[$key] = ['query' => $query, 'fingerprint' => $fingerprint, 'count' => 0, 'total' => 0.0];
            } elseif ($query->timeMs > $groups[$key]['query']->timeMs) {
                $groups[$key]['query'] = $query;
            }

            $groups[$key]['count']++;
            $groups[$key]['total'] += $query->timeMs;
        }

        $findings = [];
        foreach ($groups as $group) {
            $findings[] = $this->finding($trace, $group['query'], $group['fingerprint'], $group['count'], $group['total']);
        }

        return $findings;
    }

    private function finding(TraceData $trace, QueryRecord $slowest, string $fingerprint, int $count, float $total): Finding
    {
        $sql = $this->normalizedOf($slowest);
        $table = Sql::primaryTable($sql);
        $severity = $slowest->timeMs >= $this->criticalMs ? Severity::Critical : Severity::Warning;
        $times = $count > 1 ? ', '.$count.'×' : '';

        return new Finding(
            type: IssueType::SlowQuery,
            severity: $severity,
            title: 'Slow query on '.Describe::table($table).' ('.Describe::duration($slowest->timeMs).$times.')'.Describe::where($trace, $slowest->location),
            description: sprintf(
                'A query on %s took %s (threshold %s)%s.',
                Describe::table($table),
                Describe::duration($slowest->timeMs),
                Describe::duration($this->warningMs),
                $count > 1 ? sprintf('; it ran slowly %d times in this %s for %s in total', $count, strtolower($trace->type->label()), Describe::duration($total)) : '',
            ),
            fingerprint: $this->fingerprinter->issue(IssueType::SlowQuery, $fingerprint, $slowest->location, Describe::entry($trace)),
            metrics: [
                'time_ms' => round($slowest->timeMs, 2),
                'total_ms' => round($total, 2),
                'count' => $count,
                'threshold_ms' => $this->warningMs,
                'connection' => $slowest->connection,
                'table' => $table,
            ],
            location: $slowest->location,
            sql: $sql,
            queryFingerprint: $fingerprint,
            recommendation: self::recommend($sql),
        );
    }

    /**
     * Deterministic hints derived from the normalized SQL shape.
     */
    public static function recommend(string $sql): string
    {
        $lower = strtolower($sql);
        $verb = Sql::verb($sql);

        if (! in_array($verb, ['select', 'with'], true)) {
            return 'Make sure the columns in the WHERE clause of this '.strtoupper($verb ?: 'write').' are indexed and keep each batch small: '
                .'long-running writes hold locks and block other requests. For large updates or deletes, process rows in chunks (chunkById()).';
        }

        $hints = ['Run EXPLAIN on this query and make sure the columns used in WHERE, JOIN and ORDER BY are covered by an index (a composite index in that order works best).'];

        if (preg_match('/\bselect\s+(?:distinct\s+)?(?:\S+\.)?\*/', $lower) === 1) {
            $hints[] = 'Select only the columns you need instead of `*` (->select([...])).';
        }
        if (str_contains($lower, 'rand()') || str_contains($lower, 'random()')) {
            $hints[] = 'Avoid ORDER BY RAND(): it sorts the whole table; pick random ids in PHP or use a precomputed random column.';
        }
        if (preg_match('/\boffset\s+\?|\blimit\s+\?\s*,\s*\?/', $lower) === 1) {
            $hints[] = 'Large OFFSETs scan and discard rows; use keyset pagination (->cursorPaginate() or where id > ?).';
        }
        if (! str_contains($lower, ' limit ') && ! str_contains($lower, ' fetch ') && preg_match('/\b(count|sum|avg|min|max)\s*\(/', $lower) !== 1) {
            $hints[] = 'Limit or paginate the result set (->paginate(), ->chunkById() or ->lazy()) instead of loading every row.';
        }
        if (preg_match('/\b(count|sum|avg)\s*\(/', $lower) === 1) {
            $hints[] = 'If this aggregate is read often, cache it or maintain a counter column.';
        }
        if (preg_match('/\blike\s+\?/', $lower) === 1) {
            $hints[] = 'LIKE with a leading wildcard (\'%term\') cannot use a B-tree index; prefer prefix matches or a full-text index.';
        }

        return implode(' ', $hints);
    }
}
