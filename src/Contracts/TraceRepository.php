<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Contracts;

use Anabil\Sherlock\Data\EntryStat;
use Anabil\Sherlock\Data\QueryStat;
use Anabil\Sherlock\Data\RecordedTrace;
use Anabil\Sherlock\Data\ReleaseRecord;
use Anabil\Sherlock\Data\StoredQuery;
use Anabil\Sherlock\Data\StoredTrace;
use Anabil\Sherlock\Data\TraceFilter;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\RecordReason;
use DateTimeImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * sherlock_traces + sherlock_queries + sherlock_releases on the Sherlock connection.
 */
interface TraceRepository
{
    /** Store a trace and its queries; returns the trace id (ULID). */
    public function store(RecordedTrace $trace): string;

    public function find(string $id): ?StoredTrace;

    /**
     * @return list<StoredQuery>
     */
    public function queries(string $traceId): array;

    /**
     * @return LengthAwarePaginator<int, StoredTrace>
     */
    public function paginate(TraceFilter $filter, int $perPage = 25, int $page = 1): LengthAwarePaginator;

    /**
     * Slowest routes / jobs / commands by average duration.
     *
     * @return list<EntryStat>
     */
    public function slowestEntries(DateTimeImmutable $since, int $limit = 10, ?EntryType $type = null): array;

    /**
     * Top query fingerprints.
     *
     * @param  string  $orderBy  total | count | avg
     * @return list<QueryStat>
     */
    public function topQueries(DateTimeImmutable $since, string $orderBy = 'total', int $limit = 10): array;

    /**
     * @return array<string, int> bottleneck value => trace count
     */
    public function bottleneckDistribution(DateTimeImmutable $since): array;

    public function countSince(DateTimeImmutable $since, ?EntryType $type = null): int;

    /**
     * Releases seen by persisted traces, most recently deployed (first seen) first.
     *
     * @return list<ReleaseRecord>
     */
    public function releases(int $limit = 20): array;

    public function findRelease(string $release): ?ReleaseRecord;

    /**
     * Per-entry statistics of the persisted traces of one release, most frequent first.
     * In production only sampled / threshold traces are stored: pass RecordReason::Sample for an
     * unbiased (but smaller) population.
     *
     * @return list<EntryStat>
     */
    public function entryStatsForRelease(string $release, ?EntryType $type = null, int $limit = 50, ?RecordReason $reason = null): array;

    /** Delete traces (and their queries / issue links) created before $before, in chunks. Returns deleted traces. */
    public function pruneOlderThan(DateTimeImmutable $before, int $chunkSize = 1000): int;
}
