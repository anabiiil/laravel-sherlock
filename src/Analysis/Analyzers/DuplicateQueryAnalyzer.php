<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Analysis\Analyzers;

use Anabil\Sherlock\Analysis\Support\Describe;
use Anabil\Sherlock\Analysis\Support\ResolvesQueries;
use Anabil\Sherlock\Analysis\Support\Sql;
use Anabil\Sherlock\Contracts\Analyzer;
use Anabil\Sherlock\Contracts\Fingerprinter;
use Anabil\Sherlock\Contracts\Redactor;
use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Data\QueryRecord;
use Anabil\Sherlock\Data\SourceLocation;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;

/**
 * Identical SQL + identical bindings executed >= minCount times in one trace.
 *
 * Only possible when bindings were captured (full traces); queries without bindings
 * are skipped silently. Queries with a redacted binding are skipped too: two
 * different secrets would both read "[REDACTED]" and look identical.
 * Findings are grouped per query fingerprint + source location.
 */
final class DuplicateQueryAnalyzer implements Analyzer
{
    use ResolvesQueries;

    public function __construct(
        private readonly Fingerprinter $fingerprinter,
        private readonly int $minCount = 2,
    ) {}

    public function analyze(TraceData $trace): array
    {
        /** @var array<string, int> $identities */
        $identities = [];
        /** @var array<string, SourceLocation> $lastLocation */
        $lastLocation = [];
        /** @var array<string, array{query: QueryRecord, fingerprint: string, location: SourceLocation|null, identities: array<string, int>, max: int, wasted: float}> $groups */
        $groups = [];

        foreach ($trace->queries as $query) {
            if ($query->bindings === null || self::hasRedactedBinding($query)) {
                continue;
            }

            $fingerprint = $this->fingerprintOf($query);
            if ($query->location !== null) {
                $location = $lastLocation[$fingerprint] = $query->location;
            } else {
                $location = $lastLocation[$fingerprint] ?? null;
            }

            $identity = $query->identityKey();
            $seen = $identities[$identity] = ($identities[$identity] ?? 0) + 1;
            if ($seen < 2) {
                continue;
            }

            $key = $fingerprint."\0".($location?->toString() ?? '');
            $groups[$key] ??= ['query' => $query, 'fingerprint' => $fingerprint, 'location' => $location, 'identities' => [], 'max' => 0, 'wasted' => 0.0];
            $groups[$key]['identities'][$identity] = $seen;
            $groups[$key]['max'] = max($groups[$key]['max'], $seen);
            $groups[$key]['wasted'] += $query->timeMs;
        }

        $findings = [];
        foreach ($groups as $group) {
            $max = $group['max'];
            if ($max >= $this->minCount) {
                $extra = array_sum($group['identities']) - count($group['identities']);
                $findings[] = $this->finding($trace, $group['query'], $group['fingerprint'], $group['location'], $max, $extra, $group['wasted']);
            }
        }

        return $findings;
    }

    private function finding(TraceData $trace, QueryRecord $query, string $fingerprint, ?SourceLocation $location, int $count, int $extra, float $wasted): Finding
    {
        $sql = $this->normalizedOf($query);
        $table = Sql::primaryTable($sql);

        return new Finding(
            type: IssueType::DuplicateQuery,
            impactMs: round($wasted, 3),
            severity: Severity::Warning,
            title: 'Duplicate query on '.Describe::table($table).' ('.$count.'× with identical bindings)'.Describe::where($trace, $location),
            description: sprintf(
                'The exact same query (same SQL and bindings) on %s ran %d times; %d executions were redundant and cost %s.',
                Describe::table($table),
                $count,
                $extra,
                Describe::duration($wasted),
            ),
            fingerprint: $this->fingerprinter->issue(IssueType::DuplicateQuery, $fingerprint, $location, Describe::entry($trace)),
            metrics: [
                'count' => $count,
                'redundant' => $extra,
                'wasted_ms' => round($wasted, 2),
                'table' => $table,
            ],
            location: $location,
            sql: $sql,
            queryFingerprint: $fingerprint,
            recommendation: 'Run the query once and reuse the result: keep it in a variable, pass the loaded model along, '
                .'memoize it for the request with once() or a scoped singleton, or eager load the relation instead of fetching it repeatedly.',
        );
    }

    private static function hasRedactedBinding(QueryRecord $query): bool
    {
        foreach ($query->bindings ?? [] as $value) {
            if (is_string($value) && str_contains($value, Redactor::REDACTED)) {
                return true;
            }
        }

        return false;
    }
}
