<?php

declare(strict_types=1);

namespace Anabil\Tracing\Analysis\Analyzers;

use Anabil\Tracing\Analysis\Support\Describe;
use Anabil\Tracing\Analysis\Support\ResolvesQueries;
use Anabil\Tracing\Analysis\Support\Sql;
use Anabil\Tracing\Contracts\Analyzer;
use Anabil\Tracing\Contracts\Fingerprinter;
use Anabil\Tracing\Data\Finding;
use Anabil\Tracing\Data\TraceData;
use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Enums\Severity;

/**
 * Total query count of a trace >= warningCount (critical >= criticalCount).
 * Uses the real count, including queries dropped by capture caps.
 */
final class ExcessiveQueriesAnalyzer implements Analyzer
{
    use ResolvesQueries;

    public function __construct(
        private readonly Fingerprinter $fingerprinter,
        private readonly int $warningCount = 50,
        private readonly int $criticalCount = 200,
    ) {}

    public function analyze(TraceData $trace): array
    {
        $total = $trace->queryCount();
        if ($total < $this->warningCount) {
            return [];
        }

        // Most repeated fingerprint, only computed once the threshold is crossed.
        $counts = [];
        $sqlByFingerprint = [];
        foreach ($trace->queries as $query) {
            $fingerprint = $this->fingerprintOf($query);
            $counts[$fingerprint] = ($counts[$fingerprint] ?? 0) + 1;
            $sqlByFingerprint[$fingerprint] ??= $this->normalizedOf($query);
        }

        $topCount = 0;
        $topSql = null;
        if ($counts !== []) {
            arsort($counts);
            $topFingerprint = (string) array_key_first($counts);
            $topCount = $counts[$topFingerprint];
            $topSql = $sqlByFingerprint[$topFingerprint];
        }

        $entry = Describe::entry($trace);
        $topTable = $topSql === null ? null : Sql::primaryTable($topSql);
        $distinct = count($counts);

        return [new Finding(
            type: IssueType::ExcessiveQueries,
            severity: $total >= $this->criticalCount ? Severity::Critical : Severity::Warning,
            title: 'Excessive queries: '.$total.' queries in '.$entry,
            description: sprintf(
                'This %s ran %d queries (threshold %d) taking %s%s.',
                strtolower($trace->type->label()),
                $total,
                $this->warningCount,
                Describe::duration($trace->dbMs),
                $topCount > 1 ? sprintf('; the most repeated query on %s ran %d times', Describe::table($topTable), $topCount) : '',
            ),
            fingerprint: $this->fingerprinter->issue(IssueType::ExcessiveQueries, null, null, $entry),
            metrics: [
                'count' => $total,
                'distinct' => $distinct,
                'db_ms' => round($trace->dbMs, 2),
                'threshold' => $this->warningCount,
                'top_count' => $topCount,
                'top_table' => $topTable,
            ],
            sql: $topCount > 1 ? $topSql : null,
            recommendation: 'Reduce the number of round trips: eager load relations with with() to remove N+1 patterns, '
                .'load related records in bulk with whereIn(), replace per-row inserts and updates with insert([...]) / upsert(), '
                .'and cache data that rarely changes (settings, lookups, permissions).',
        )];
    }
}
