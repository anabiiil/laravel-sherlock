<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Analysis\Analyzers;

use Anabil\Sherlock\Analysis\Support\Describe;
use Anabil\Sherlock\Analysis\Support\ResolvesQueries;
use Anabil\Sherlock\Analysis\Support\Sql;
use Anabil\Sherlock\Contracts\Analyzer;
use Anabil\Sherlock\Contracts\Fingerprinter;
use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Data\LazyLoadRecord;
use Anabil\Sherlock\Data\QueryRecord;
use Anabil\Sherlock\Data\SourceLocation;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;
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
 *
 * Lazy-loading violations (TraceData::$lazyLoads, see LazyLoadingCollector) name the exact
 * relation: a group whose table is the related table of a recorded "Model::relation" gets that
 * name instead of the guess from the SQL. A recorded violation without enough repeats (or
 * without a matching group) still produces an info-level N+1 finding: it happened on a
 * collection, so it becomes an N+1 as soon as the collection grows.
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
        if (count($trace->queries) < $this->warningCount && $trace->lazyLoads === []) {
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

        /** @var array<string, LazyLoadRecord> $lazy unmatched lazy loads, "Model::relation" => record */
        $lazy = [];
        foreach ($trace->lazyLoads as $record) {
            $lazy[$record->model.'::'.$record->relation] = $record;
        }

        $findings = [];
        foreach ($groups as $group) {
            if ($group['count'] >= $this->warningCount) {
                $match = self::matchLazyLoad($lazy, $this->tableOf($group['query']), $group['count']);
                if ($match !== null) {
                    unset($lazy[$match->model.'::'.$match->relation]);
                }

                $findings[] = $this->finding($trace, $group['query'], $group['fingerprint'], $group['location'], $group['count'], $group['total'], $match);
            }
        }

        foreach ($lazy as $record) {
            $findings[] = $this->lazyLoadFinding($trace, $record, $groups);
        }

        return $findings;
    }

    private function tableOf(QueryRecord $query): ?string
    {
        return Sql::primaryTable($this->normalizedOf($query));
    }

    /**
     * The recorded lazy load whose related table is $table, the one whose count is closest to $count.
     *
     * @param  array<string, LazyLoadRecord>  $lazy
     */
    private static function matchLazyLoad(array $lazy, ?string $table, int $count): ?LazyLoadRecord
    {
        if ($table === null) {
            return null;
        }

        $best = null;
        foreach ($lazy as $record) {
            if ($record->table !== null && strcasecmp($record->table, $table) === 0
                && ($best === null || abs($record->count - $count) < abs($best->count - $count))) {
                $best = $record;
            }
        }

        return $best;
    }

    /**
     * Info finding for a lazy load on a collection that did not (yet) repeat enough.
     *
     * @param  array<string, array{query: QueryRecord, fingerprint: string, location: SourceLocation|null, count: int, total: float}>  $groups
     */
    private function lazyLoadFinding(TraceData $trace, LazyLoadRecord $record, array $groups): Finding
    {
        $group = null;
        foreach ($groups as $candidate) {
            if ($record->table !== null && strcasecmp((string) $this->tableOf($candidate['query']), $record->table) === 0
                && ($group === null || abs($candidate['count'] - $record->count) < abs($group['count'] - $record->count))) {
                $group = $candidate;
            }
        }

        $model = self::shortModel($record->model);
        $where = Describe::where($trace, $group['location'] ?? null);

        return new Finding(
            type: IssueType::NPlusOne,
            severity: Severity::Info,
            title: 'Lazy loading of '.$record->shortName().' on a collection ('.$record->count.'×)'.$where,
            description: sprintf(
                'The relation %s was lazy loaded on %d %s of a collection (Model::preventLazyLoading() violation). '
                .'Each one runs its own query: it becomes an N+1 as the collection grows.',
                $record->model.'::'.$record->relation,
                $record->count,
                $record->count === 1 ? 'model' : 'models',
            ),
            fingerprint: $this->fingerprinter->issue(IssueType::NPlusOne, 'lazy:'.$record->model.'::'.$record->relation, null, Describe::entry($trace)),
            metrics: [
                'count' => $record->count,
                'total_ms' => $group !== null ? round($group['total'], 2) : null,
                'threshold' => $this->warningCount,
                'table' => $record->table,
                'relation' => $record->relation,
                'model' => $record->model,
                'relation_source' => 'lazy_loading',
            ],
            location: $group['location'] ?? null,
            sql: $group !== null ? $this->normalizedOf($group['query']) : null,
            queryFingerprint: $group['fingerprint'] ?? null,
            recommendation: self::recommendEagerLoading($record->relation, $model, max(2, $record->count)),
            impactMs: $group !== null ? round($group['total'], 3) : 0.0,
        );
    }

    private static function shortModel(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    private function finding(TraceData $trace, QueryRecord $query, string $fingerprint, ?SourceLocation $location, int $count, float $total, ?LazyLoadRecord $lazy = null): Finding
    {
        $sql = $this->normalizedOf($query);
        $table = Sql::primaryTable($sql);
        $isSelect = in_array(Sql::verb($sql), ['select', 'with'], true);
        [$relation, $parent] = $lazy !== null ? [$lazy->relation, self::shortModel($lazy->model)] : self::guessRelation($sql, $table);

        return new Finding(
            type: IssueType::NPlusOne,
            impactMs: round($total, 3),
            severity: $count >= $this->criticalCount ? Severity::Critical : Severity::Warning,
            title: 'N+1 query on '.Describe::table($table).' ('.$count.'×)'.($lazy !== null ? ' lazy loading '.$lazy->shortName() : '').Describe::where($trace, $location),
            description: sprintf(
                'The same query on %s ran %d times from the same place (%s in total, %s on average). '
                .'%s',
                Describe::table($table),
                $count,
                Describe::duration($total),
                Describe::duration($total / $count),
                $lazy !== null
                    ? 'Eloquent reported the relation '.$lazy->model.'::'.$lazy->relation.' as lazy loaded on a collection ('.$lazy->count.'×).'
                    : 'This usually means a relation is lazy loaded inside a loop.',
            ),
            fingerprint: $this->fingerprinter->issue(IssueType::NPlusOne, $fingerprint, $location, Describe::entry($trace)),
            metrics: [
                'count' => $count,
                'total_ms' => round($total, 2),
                'avg_ms' => round($total / $count, 2),
                'threshold' => $this->warningCount,
                'table' => $table,
                'relation' => $relation,
                'model' => $lazy?->model,
                'relation_source' => $lazy !== null ? 'lazy_loading' : 'guess',
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
