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
use Anabil\Tracing\Data\SourceLocation;
use Anabil\Tracing\Data\TraceData;
use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Enums\Severity;
use Illuminate\Support\Str;

/**
 * Same query fingerprint + same source location repeated >= warningCount times in
 * one trace (critical >= criticalCount).
 *
 * Capture only takes a backtrace for one occurrence of a SQL string (the first in full
 * capture, the second in lightweight capture) and for slow queries, so a query without a
 * location inherits the last location seen for its fingerprint, or, before any was seen
 * (the first execution in lightweight capture), the first one seen later. Without any
 * location (capture disabled) the fingerprint alone groups.
 */
final class NPlusOneAnalyzer implements Analyzer
{
    use ResolvesQueries;

    public function __construct(
        private readonly Fingerprinter $fingerprinter,
        private readonly int $warningCount = 5,
        private readonly int $criticalCount = 20,
    ) {}

    public function analyze(TraceData $trace): array
    {
        if (count($trace->queries) < $this->warningCount) {
            return [];
        }

        /** @var array<string, SourceLocation> $firstLocation */
        $firstLocation = [];
        foreach ($trace->queries as $query) {
            if ($query->location !== null) {
                $firstLocation[$this->fingerprintOf($query)] ??= $query->location;
            }
        }

        /** @var array<string, SourceLocation> $lastLocation */
        $lastLocation = [];
        /** @var array<string, array{query: QueryRecord, fingerprint: string, location: SourceLocation|null, count: int, total: float}> $groups */
        $groups = [];

        foreach ($trace->queries as $query) {
            $fingerprint = $this->fingerprintOf($query);

            if ($query->location !== null) {
                $location = $lastLocation[$fingerprint] = $query->location;
            } else {
                $location = $lastLocation[$fingerprint] ?? $firstLocation[$fingerprint] ?? null;
            }

            $key = $location === null ? $fingerprint : $fingerprint."\0".$location->file."\0".$location->line;

            if (isset($groups[$key])) {
                $groups[$key]['count']++;
                $groups[$key]['total'] += $query->timeMs;
            } else {
                $groups[$key] = ['query' => $query, 'fingerprint' => $fingerprint, 'location' => $location, 'count' => 1, 'total' => $query->timeMs];
            }
        }

        $findings = [];
        foreach ($groups as $group) {
            if ($group['count'] >= $this->warningCount) {
                $findings[] = $this->finding($trace, $group['query'], $group['fingerprint'], $group['location'], $group['count'], $group['total']);
            }
        }

        return $findings;
    }

    private function finding(TraceData $trace, QueryRecord $query, string $fingerprint, ?SourceLocation $location, int $count, float $total): Finding
    {
        $sql = $this->normalizedOf($query);
        $table = Sql::primaryTable($sql);
        $isSelect = in_array(Sql::verb($sql), ['select', 'with'], true);
        [$relation, $parent] = self::guessRelation($sql, $table);

        return new Finding(
            type: IssueType::NPlusOne,
            severity: $count >= $this->criticalCount ? Severity::Critical : Severity::Warning,
            title: 'N+1 query on '.Describe::table($table).' ('.$count.'×)'.Describe::where($trace, $location),
            description: sprintf(
                'The same query on %s ran %d times from the same place (%s in total, %s on average). '
                .'This usually means a relation is lazy loaded inside a loop.',
                Describe::table($table),
                $count,
                Describe::duration($total),
                Describe::duration($total / $count),
            ),
            fingerprint: $this->fingerprinter->issue(IssueType::NPlusOne, $fingerprint, $location, Describe::entry($trace)),
            metrics: [
                'count' => $count,
                'total_ms' => round($total, 2),
                'avg_ms' => round($total / $count, 2),
                'threshold' => $this->warningCount,
                'table' => $table,
                'relation' => $relation,
            ],
            location: $location,
            sql: $sql,
            queryFingerprint: $fingerprint,
            recommendation: $isSelect
                ? self::recommendEagerLoading($relation, $parent, $count)
                : sprintf(
                    'This write runs once per item (%d×). Batch it: insert([...]) / upsert() for many rows, or whereIn(...)->update() instead of saving models one by one in a loop.',
                    $count,
                ),
        );
    }

    private static function recommendEagerLoading(?string $relation, ?string $parent, int $count): string
    {
        $name = $relation ?? 'relation';
        $model = $parent ?? 'Model';

        return sprintf(
            "Eager load the relation with with(): `%s::with('%s')->get()` (or `\$models->load('%s')` on an existing collection) turns these %d queries into 1. "
            .'Calling Model::preventLazyLoading() in a non-production environment makes such lazy loads throw so they are caught early.',
            $model,
            $name,
            $name,
            $count,
        );
    }

    /**
     * Guess the Eloquent relation name from the lookup column:
     *  - where `posts`.`user_id` = ?  -> hasMany "posts" on User
     *  - where `users`.`id` = ?       -> belongsTo "user"
     *
     * @return array{0: string|null, 1: string|null} [relation, parent model]
     */
    public static function guessRelation(string $sql, ?string $table): array
    {
        if ($table === null) {
            return [null, null];
        }

        $where = Sql::whereClause($sql);
        $column = null;
        foreach ($where === null ? [] : Sql::predicates($where) as [, $candidate, $equality]) {
            if ($equality) {
                $column = strtolower($candidate);

                break;
            }
        }

        if ($column !== null && $column !== 'id' && str_ends_with($column, '_id')) {
            return [Str::camel($table), Str::studly(Str::singular(substr($column, 0, -3)))];
        }

        if ($column === 'id') {
            return [Str::camel(Str::singular($table)), null];
        }

        return [Str::camel($table), null];
    }
}
