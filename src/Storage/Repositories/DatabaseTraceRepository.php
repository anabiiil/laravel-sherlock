<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Storage\Repositories;

use Anabil\Sherlock\Contracts\Fingerprinter;
use Anabil\Sherlock\Contracts\QueryNormalizer;
use Anabil\Sherlock\Contracts\TraceRepository;
use Anabil\Sherlock\Data\EntryStat;
use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Data\LazyLoadRecord;
use Anabil\Sherlock\Data\QueryRecord;
use Anabil\Sherlock\Data\QueryStat;
use Anabil\Sherlock\Data\RecordedTrace;
use Anabil\Sherlock\Data\ReleaseRecord;
use Anabil\Sherlock\Data\StoredQuery;
use Anabil\Sherlock\Data\StoredTrace;
use Anabil\Sherlock\Data\TraceFilter;
use Anabil\Sherlock\Enums\Bottleneck;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\RecordReason;
use Anabil\Sherlock\Storage\Support\Values;
use Anabil\Sherlock\Storage\Tables;
use DateTimeImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * sherlock_traces + sherlock_queries (+ sherlock_releases) through the query builder (no models,
 * no hydration overhead). A trace costs one insert plus one multi-row insert per chunk of queries,
 * inside one transaction.
 *
 * Releases: a trace with a release upserts its sherlock_releases row at most once per process per
 * release, then refreshes last_seen_at at most every RELEASE_TOUCH_SECONDS (persist path only).
 */
final class DatabaseTraceRepository implements TraceRepository
{
    /** Rows per multi-row insert (keeps statements well below max_allowed_packet / placeholder limits). */
    private const INSERT_CHUNK = 100;

    /** Trace ids per child delete while pruning (keeps each DELETE short). */
    private const CHILD_DELETE_CHUNK = 100;

    /** Seconds between two last_seen_at refreshes of the same release by one process. */
    public const RELEASE_TOUCH_SECONDS = 300;

    private const TRACE_COLUMNS = [
        'id', 'type', 'name', 'method', 'status_code', 'duration_ms', 'db_ms', 'db_count', 'http_ms',
        'http_count', 'cache_hits', 'cache_misses', 'bootstrap_ms', 'memory_peak', 'bottleneck',
        'verdict_reason', 'record_reason', 'user_id', 'details', 'created_at',
        'release', 'cpu_ms', 'load_avg', 'cpu_cores', 'memory_free_mb', 'queue_wait_ms',
    ];

    /** @var array<string, int> release => unix time of the last upsert / touch by this process */
    private array $releasesTouched = [];

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly Tables $tables,
        private readonly ?string $connection = null,
        private readonly int $maxQueriesPerTrace = 200,
        private readonly ?QueryNormalizer $normalizer = null,
        private readonly ?Fingerprinter $fingerprinter = null,
    ) {}

    public function store(RecordedTrace $trace): string
    {
        $data = $trace->trace;
        $verdict = $trace->analysis->verdict;
        $createdAt = Values::date($data->startedAt);
        $queries = $this->selectQueries($data->queries);

        $row = [
            'id' => $data->id,
            'type' => $data->type->value,
            'name' => (string) Values::truncate($data->name, 255),
            'method' => Values::truncate($data->method, 10),
            'status_code' => $data->statusCode,
            'duration_ms' => round($data->durationMs, 3),
            'db_ms' => round($data->dbMs, 3),
            'db_count' => $data->queryCount(),
            'http_ms' => round($data->httpMs, 3),
            'http_count' => $data->httpCount(),
            'cache_hits' => $data->cacheHits,
            'cache_misses' => $data->cacheMisses,
            'bootstrap_ms' => round($data->bootstrapMs, 3),
            'memory_peak' => max(0, $data->memoryPeakBytes),
            'bottleneck' => $verdict->bottleneck->value,
            'verdict_reason' => Values::truncate($verdict->reason, 1000),
            'record_reason' => $trace->reason->value,
            'user_id' => $data->userId === null ? null : Values::truncate((string) $data->userId, 64),
            'details' => Values::json([
                'uri' => $data->uri,
                'route' => $data->route,
                'failed' => $data->failed,
                'full' => $data->full,
                'cache_writes' => $data->cacheWrites,
                'cache_ms' => $data->cacheMs,
                'record_reasons' => array_map(static fn (RecordReason $r): string => $r->value, $data->recordReasons),
                'tags' => $data->tags,
                'metadata' => $data->metadata,
                'verdict' => $verdict->toArray(),
                'stored_queries' => count($queries),
                'http_calls' => array_map(static fn ($call): array => $call->toArray(), $data->httpCalls),
                'logs' => array_map(static fn ($log): array => $log->toArray(), $data->logs),
                'findings' => array_map(static fn (Finding $f): array => $f->toArray(), $trace->analysis->findings),
                'lazy_loads' => array_map(static fn (LazyLoadRecord $l): array => $l->toArray(), $data->lazyLoads),
                'memory_leak' => $data->memoryLeak?->toArray(),
            ]),
            'created_at' => $createdAt,
            'release' => Values::truncate($data->release, 64),
            'cpu_ms' => $data->cpuMs === null ? null : round($data->cpuMs, 3),
            'load_avg' => $data->loadAvg === null ? null : round($data->loadAvg, 3),
            'cpu_cores' => $data->cpuCores === null ? null : max(0, min(65535, $data->cpuCores)),
            'memory_free_mb' => $data->memoryFreeMb === null ? null : max(0, $data->memoryFreeMb),
            'queue_wait_ms' => $data->queueWaitMs === null ? null : round($data->queueWaitMs, 3),
        ];

        $queryRows = [];
        foreach ($queries as $query) {
            $queryRows[] = $this->queryRow($data->id, $query, $trace->storeBindings, $createdAt);
        }

        $connection = $this->connection();
        $connection->transaction(function () use ($connection, $row, $queryRows): void {
            $connection->table($this->tables->traces())->insert($row);

            foreach (array_chunk($queryRows, self::INSERT_CHUNK) as $chunk) {
                $connection->table($this->tables->queries())->insert($chunk);
            }
        });

        if ($row['release'] !== null) {
            $this->touchRelease($row['release'], $createdAt);
        }

        return $data->id;
    }

    /**
     * Upsert the release row: insertOrIgnore once per process per release, then a conditional
     * last_seen_at refresh at most every RELEASE_TOUCH_SECONDS.
     */
    public function touchRelease(string $release, string $seenAt): void
    {
        $now = time();
        $touched = $this->releasesTouched[$release] ?? null;
        if ($touched !== null && $now - $touched < self::RELEASE_TOUCH_SECONDS) {
            return;
        }
        $this->releasesTouched[$release] = $now;

        $releases = $this->connection()->table($this->tables->releases());

        if ($touched === null && $releases->insertOrIgnore([
            'release' => $release,
            'first_seen_at' => $seenAt,
            'last_seen_at' => $seenAt,
        ]) > 0) {
            return;
        }

        $this->connection()->table($this->tables->releases())
            ->where('release', $release)
            ->where('last_seen_at', '<', $seenAt)
            ->update(['last_seen_at' => $seenAt]);
    }

    public function releases(int $limit = 20): array
    {
        return array_values($this->connection()->table($this->tables->releases())
            ->orderByDesc('first_seen_at')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(static fn (object $row): ReleaseRecord => new ReleaseRecord(
                Values::string($row->release ?? null),
                Values::toDate($row->first_seen_at ?? null) ?? new DateTimeImmutable,
                Values::toDate($row->last_seen_at ?? null) ?? new DateTimeImmutable,
            ))
            ->all());
    }

    public function findRelease(string $release): ?ReleaseRecord
    {
        $row = $this->connection()->table($this->tables->releases())->where('release', $release)->first();

        return $row === null ? null : new ReleaseRecord(
            Values::string($row->release ?? null),
            Values::toDate($row->first_seen_at ?? null) ?? new DateTimeImmutable,
            Values::toDate($row->last_seen_at ?? null) ?? new DateTimeImmutable,
        );
    }

    public function entryStatsForRelease(string $release, ?EntryType $type = null, int $limit = 50, ?RecordReason $reason = null): array
    {
        $query = $this->connection()->table($this->tables->traces())
            ->selectRaw('type, name, COUNT(*) as aggregate_count, AVG(duration_ms) as avg_ms, MAX(duration_ms) as max_ms, AVG(db_ms) as avg_db_ms, AVG(db_count) as avg_queries')
            ->where('release', $release)
            ->groupBy('type', 'name')
            ->orderByDesc('aggregate_count')
            ->orderByDesc('avg_ms')
            ->limit(max(1, $limit));

        if ($type !== null) {
            $query->where('type', $type->value);
        }

        if ($reason !== null) {
            $query->where('record_reason', $reason->value);
        }

        return $this->entryStats($query);
    }

    public function find(string $id): ?StoredTrace
    {
        $row = $this->connection()->table($this->tables->traces())->where('id', $id)->first();

        return $row === null ? null : $this->hydrateTrace($row);
    }

    public function queries(string $traceId): array
    {
        return array_values($this->connection()->table($this->tables->queries())
            ->where('trace_id', $traceId)
            ->orderBy('offset_ms')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): StoredQuery => $this->hydrateQuery($row))
            ->all());
    }

    /**
     * @return LengthAwarePaginatorContract<int, StoredTrace>
     */
    public function paginate(TraceFilter $filter, int $perPage = 25, int $page = 1): LengthAwarePaginatorContract
    {
        $perPage = max(1, $perPage);
        $page = max(1, $page);
        $query = $this->connection()->table($this->tables->traces());

        if ($filter->type !== null) {
            $query->where('type', $filter->type->value);
        }

        if ($filter->name !== null && $filter->name !== '') {
            $query->where('name', $filter->name);
        }

        if ($filter->bottleneck !== null) {
            $query->where('bottleneck', $filter->bottleneck->value);
        }

        if ($filter->minDurationMs !== null) {
            $query->where('duration_ms', '>=', $filter->minDurationMs);
        }

        if ($filter->since !== null) {
            $query->where('created_at', '>=', Values::date($filter->since));
        }

        if ($filter->release !== null && $filter->release !== '') {
            $query->where('release', $filter->release);
        }

        if ($filter->issueId !== null) {
            $query->whereIn('id', function (Builder $sub) use ($filter): void {
                $sub->select('trace_id')->from($this->tables->issueTraces())->where('issue_id', $filter->issueId);
            });
        }

        $total = (clone $query)->count();

        if ($filter->sort === 'duration') {
            $query->orderByDesc('duration_ms');
        } else {
            $query->orderByDesc('created_at');
        }

        $items = $total === 0 ? [] : $query->orderByDesc('id')
            ->select(self::TRACE_COLUMNS)
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (object $row): StoredTrace => $this->hydrateTrace($row))
            ->values()
            ->all();

        return new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }

    public function slowestEntries(DateTimeImmutable $since, int $limit = 10, ?EntryType $type = null): array
    {
        $query = $this->connection()->table($this->tables->traces())
            ->selectRaw('type, name, COUNT(*) as aggregate_count, AVG(duration_ms) as avg_ms, MAX(duration_ms) as max_ms, AVG(db_ms) as avg_db_ms, AVG(db_count) as avg_queries')
            ->where('created_at', '>=', Values::date($since))
            ->groupBy('type', 'name')
            ->orderByDesc('avg_ms')
            ->limit(max(1, $limit));

        if ($type !== null) {
            $query->where('type', $type->value);
        }

        return $this->entryStats($query);
    }

    /**
     * @return list<EntryStat>
     */
    private function entryStats(Builder $query): array
    {
        $stats = [];
        foreach ($query->get() as $row) {
            $entryType = EntryType::tryFrom(Values::string($row->type ?? null));

            if ($entryType === null) {
                continue;
            }

            $stats[] = new EntryStat(
                $entryType,
                Values::string($row->name ?? null),
                Values::int($row->aggregate_count ?? null),
                Values::float($row->avg_ms ?? null),
                Values::float($row->max_ms ?? null),
                Values::float($row->avg_db_ms ?? null),
                Values::float($row->avg_queries ?? null),
            );
        }

        return $stats;
    }

    public function topQueries(DateTimeImmutable $since, string $orderBy = 'total', int $limit = 10): array
    {
        $order = match ($orderBy) {
            'count' => 'aggregate_count',
            'avg' => 'avg_ms',
            default => 'total_ms',
        };

        $rows = $this->connection()->table($this->tables->queries())
            ->selectRaw('fingerprint, MIN(normalized_sql) as normalized_sql, COUNT(*) as aggregate_count, SUM(duration_ms) as total_ms, AVG(duration_ms) as avg_ms, MAX(duration_ms) as max_ms')
            ->where('created_at', '>=', Values::date($since))
            ->groupBy('fingerprint')
            ->orderByDesc($order)
            ->limit(max(1, $limit))
            ->get();

        $stats = [];
        foreach ($rows as $row) {
            $stats[] = new QueryStat(
                Values::string($row->fingerprint ?? null),
                Values::string($row->normalized_sql ?? null),
                Values::int($row->aggregate_count ?? null),
                Values::float($row->total_ms ?? null),
                Values::float($row->avg_ms ?? null),
                Values::float($row->max_ms ?? null),
            );
        }

        return $stats;
    }

    public function bottleneckDistribution(DateTimeImmutable $since): array
    {
        $rows = $this->connection()->table($this->tables->traces())
            ->selectRaw('bottleneck, COUNT(*) as aggregate_count')
            ->where('created_at', '>=', Values::date($since))
            ->groupBy('bottleneck')
            ->orderByDesc('aggregate_count')
            ->get();

        $distribution = [];
        foreach ($rows as $row) {
            $distribution[Values::string($row->bottleneck ?? null)] = Values::int($row->aggregate_count ?? null);
        }

        return $distribution;
    }

    public function countSince(DateTimeImmutable $since, ?EntryType $type = null): int
    {
        $query = $this->connection()->table($this->tables->traces())->where('created_at', '>=', Values::date($since));

        if ($type !== null) {
            $query->where('type', $type->value);
        }

        return $query->count();
    }

    public function pruneOlderThan(DateTimeImmutable $before, int $chunkSize = 1000): int
    {
        $connection = $this->connection();
        $cutoff = Values::date($before);
        $chunkSize = max(1, $chunkSize);
        $deleted = 0;

        do {
            /** @var list<string> $ids */
            $ids = $connection->table($this->tables->traces())
                ->where('created_at', '<', $cutoff)
                ->orderBy('created_at')
                ->limit($chunkSize)
                ->pluck('id')
                ->map(static fn (mixed $id): string => Values::string($id))
                ->all();

            if ($ids === []) {
                break;
            }

            // Children first, in short statements; a crash mid-way leaves only orphans the next run removes.
            foreach (array_chunk($ids, self::CHILD_DELETE_CHUNK) as $batch) {
                $connection->table($this->tables->queries())->whereIn('trace_id', $batch)->delete();
                $connection->table($this->tables->issueTraces())->whereIn('trace_id', $batch)->delete();
            }

            $deleted += $connection->table($this->tables->traces())->whereIn('id', $ids)->delete();
        } while (count($ids) === $chunkSize);

        return $deleted;
    }

    /**
     * Keep at most $maxQueriesPerTrace rows: the slowest execution of every fingerprint first,
     * then the slowest of the rest, in execution order.
     *
     * @param  list<QueryRecord>  $queries
     * @return list<QueryRecord>
     */
    private function selectQueries(array $queries): array
    {
        $queries = array_map(fn (QueryRecord $q): QueryRecord => $this->normalized($q), $queries);
        $limit = max(0, $this->maxQueriesPerTrace);

        if (count($queries) <= $limit) {
            return $queries;
        }

        $order = array_keys($queries);
        usort($order, static fn (int $a, int $b): int => $queries[$b]->timeMs <=> $queries[$a]->timeMs);

        $keep = [];
        $seen = [];
        foreach ($order as $index) {
            $fingerprint = (string) $queries[$index]->fingerprint;

            if (! isset($seen[$fingerprint]) && count($keep) < $limit) {
                $seen[$fingerprint] = true;
                $keep[$index] = true;
            }
        }

        foreach ($order as $index) {
            if (count($keep) >= $limit) {
                break;
            }

            $keep[$index] = true;
        }

        ksort($keep);

        return array_values(array_intersect_key($queries, $keep));
    }

    private function normalized(QueryRecord $query): QueryRecord
    {
        if ($query->normalizedSql !== null && $query->fingerprint !== null) {
            return $query;
        }

        $normalized = $query->normalizedSql ?? ($this->normalizer?->normalize($query->sql) ?? $query->sql);
        $fingerprint = $query->fingerprint ?? ($this->fingerprinter?->query($normalized) ?? hash('xxh128', strtolower($normalized)));

        return $query->withNormalization($normalized, $fingerprint);
    }

    /**
     * @return array<string, mixed>
     */
    private function queryRow(string $traceId, QueryRecord $query, bool $storeBindings, string $createdAt): array
    {
        return [
            'trace_id' => $traceId,
            'fingerprint' => (string) Values::truncate($query->fingerprint, 64),
            'normalized_sql' => (string) $query->normalizedSql,
            'bindings' => $storeBindings && $query->bindings !== null ? Values::json($query->bindings) : null,
            'duration_ms' => round($query->timeMs, 3),
            'offset_ms' => round($query->offsetMs, 3),
            'connection' => (string) Values::truncate($query->connection, 64),
            'file' => Values::truncate($query->location?->file, 512),
            'line' => $query->location === null ? null : max(0, $query->location->line),
            'created_at' => $createdAt,
        ];
    }

    private function hydrateTrace(object $row): StoredTrace
    {
        /** @var array<string, mixed> $details */
        $details = Values::fromJson($row->details ?? null) ?? [];

        return new StoredTrace(
            Values::string($row->id ?? null),
            EntryType::tryFrom(Values::string($row->type ?? null)) ?? EntryType::Request,
            Values::string($row->name ?? null),
            Values::nullableString($row->method ?? null),
            Values::nullableInt($row->status_code ?? null),
            Values::float($row->duration_ms ?? null),
            Values::float($row->db_ms ?? null),
            Values::int($row->db_count ?? null),
            Values::float($row->http_ms ?? null),
            Values::int($row->http_count ?? null),
            Values::int($row->cache_hits ?? null),
            Values::int($row->cache_misses ?? null),
            Values::float($row->bootstrap_ms ?? null),
            Values::int($row->memory_peak ?? null),
            Bottleneck::tryFrom(Values::string($row->bottleneck ?? null)) ?? Bottleneck::Unknown,
            Values::nullableString($row->verdict_reason ?? null),
            RecordReason::tryFrom(Values::string($row->record_reason ?? null)) ?? RecordReason::Threshold,
            Values::userId($row->user_id ?? null),
            Values::toDate($row->created_at ?? null) ?? new DateTimeImmutable,
            $details,
            Values::nullableString($row->release ?? null),
            self::nullableFloat($row->cpu_ms ?? null),
            self::nullableFloat($row->load_avg ?? null),
            Values::nullableInt($row->cpu_cores ?? null),
            Values::nullableInt($row->memory_free_mb ?? null),
            self::nullableFloat($row->queue_wait_ms ?? null),
        );
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function hydrateQuery(object $row): StoredQuery
    {
        /** @var array<int|string, scalar|null>|null $bindings */
        $bindings = Values::fromJson($row->bindings ?? null);

        return new StoredQuery(
            Values::id($row->id ?? null),
            Values::string($row->trace_id ?? null),
            Values::string($row->fingerprint ?? null),
            Values::string($row->normalized_sql ?? null),
            $bindings,
            Values::float($row->duration_ms ?? null),
            Values::string($row->connection ?? null),
            Values::nullableString($row->file ?? null),
            Values::nullableInt($row->line ?? null),
            Values::toDate($row->created_at ?? null) ?? new DateTimeImmutable,
            Values::float($row->offset_ms ?? null),
        );
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->connection);
    }
}
