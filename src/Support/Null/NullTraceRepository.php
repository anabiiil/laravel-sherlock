<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Support\Null;

use Anabil\Sherlock\Contracts\TraceRepository;
use Anabil\Sherlock\Data\RecordedTrace;
use Anabil\Sherlock\Data\ReleaseRecord;
use Anabil\Sherlock\Data\StoredTrace;
use Anabil\Sherlock\Data\TraceFilter;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\RecordReason;
use DateTimeImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;

final class NullTraceRepository implements TraceRepository
{
    public function store(RecordedTrace $trace): string
    {
        return $trace->trace->id;
    }

    public function find(string $id): ?StoredTrace
    {
        return null;
    }

    public function queries(string $traceId): array
    {
        return [];
    }

    /**
     * @return LengthAwarePaginatorContract<int, StoredTrace>
     */
    public function paginate(TraceFilter $filter, int $perPage = 25, int $page = 1): LengthAwarePaginatorContract
    {
        /** @var LengthAwarePaginator<int, StoredTrace> */
        return new LengthAwarePaginator([], 0, $perPage, $page);
    }

    public function slowestEntries(DateTimeImmutable $since, int $limit = 10, ?EntryType $type = null): array
    {
        return [];
    }

    public function topQueries(DateTimeImmutable $since, string $orderBy = 'total', int $limit = 10): array
    {
        return [];
    }

    public function bottleneckDistribution(DateTimeImmutable $since): array
    {
        return [];
    }

    public function releases(int $limit = 20): array
    {
        return [];
    }

    public function findRelease(string $release): ?ReleaseRecord
    {
        return null;
    }

    public function entryStatsForRelease(string $release, ?EntryType $type = null, int $limit = 50, ?RecordReason $reason = null): array
    {
        return [];
    }

    public function countSince(DateTimeImmutable $since, ?EntryType $type = null): int
    {
        return 0;
    }

    public function pruneOlderThan(DateTimeImmutable $before, int $chunkSize = 1000): int
    {
        return 0;
    }
}
