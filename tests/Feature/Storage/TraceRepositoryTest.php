<?php

declare(strict_types=1);

use Anabil\Sherlock\Contracts\TraceRepository;
use Anabil\Sherlock\Data\QueryStat;
use Anabil\Sherlock\Data\StoredQuery;
use Anabil\Sherlock\Data\StoredTrace;
use Anabil\Sherlock\Data\TraceFilter;
use Anabil\Sherlock\Enums\Bottleneck;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\RecordReason;
use Anabil\Sherlock\Storage\Repositories\DatabaseTraceRepository;
use Anabil\Sherlock\Tests\Feature\Storage\StorageFixtures as F;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function storageTraces(): DatabaseTraceRepository
{
    return app(TraceRepository::class);
}

it('is bound to the database repository', function (): void {
    expect(app(TraceRepository::class))->toBeInstanceOf(DatabaseTraceRepository::class);
});

it('stores a trace with one insert plus one multi-row insert per 100 queries, in one transaction', function (): void {
    $queries = [];
    for ($i = 0; $i < 150; $i++) {
        $queries[] = F::query(timeMs: 1.0 + $i / 100, offsetMs: (float) $i);
    }
    config()->set('sherlock.storage.max_queries_per_trace', 500);
    app()->forgetInstance(TraceRepository::class);

    $statements = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $e) use (&$statements): void {
        $statements[] = $e->sql;
    });

    $id = storageTraces()->store(F::recorded(F::traceData($queries)));

    $inserts = array_values(array_filter($statements, static fn (string $sql): bool => str_starts_with(strtolower($sql), 'insert')));

    expect($inserts)->toHaveCount(3)
        ->and($inserts[0])->toContain('sherlock_traces')
        ->and($inserts[1])->toContain('sherlock_queries')
        ->and(DB::table('sherlock_queries')->where('trace_id', $id)->count())->toBe(150);
});

it('round-trips a stored trace into a StoredTrace', function (): void {
    $data = F::traceData([F::query(timeMs: 180.0)], startedAt: CarbonImmutable::parse('2026-10-01 10:00:00'));
    $id = storageTraces()->store(F::recorded($data, [F::finding()]));

    $stored = storageTraces()->find($id);

    expect($id)->toBe($data->id)
        ->and($stored)->toBeInstanceOf(StoredTrace::class)
        ->and($stored->type)->toBe(EntryType::Request)
        ->and($stored->name)->toBe('GET /users')
        ->and($stored->method)->toBe('GET')
        ->and($stored->statusCode)->toBe(200)
        ->and($stored->durationMs)->toBe(250.0)
        ->and($stored->dbMs)->toBe(180.0)
        ->and($stored->dbCount)->toBe(1)
        ->and($stored->cacheHits)->toBe(3)
        ->and($stored->cacheMisses)->toBe(1)
        ->and($stored->bootstrapMs)->toBe(12.5)
        ->and($stored->memoryPeak)->toBe(8_388_608)
        ->and($stored->bottleneck)->toBe(Bottleneck::Database)
        ->and($stored->verdictReason)->toBe('72% of 250ms spent in 1 query')
        ->and($stored->recordReason)->toBe(RecordReason::Local)
        ->and($stored->userId)->toBe(15)
        ->and($stored->createdAt->format('Y-m-d H:i:s'))->toBe('2026-10-01 10:00:00')
        ->and($stored->details['uri'])->toBe('/users?page=2')
        ->and($stored->details['route'])->toBe('users.index')
        ->and($stored->details['tags'])->toBe(['tenant' => 'acme'])
        ->and($stored->details['findings'][0]['fingerprint'])->toBe('issue-slow-users')
        ->and($stored->details['verdict']['bottleneck'])->toBe('database')
        ->and(storageTraces()->find('01NOTFOUND000000000000000'))->toBeNull();
});

it('stores redacted bindings only when storeBindings is true', function (): void {
    $with = storageTraces()->store(F::recorded(F::traceData([F::query(bindings: ['[REDACTED]', 7])]), storeBindings: true));
    $without = storageTraces()->store(F::recorded(F::traceData([F::query(bindings: ['[REDACTED]', 7])]), storeBindings: false));

    expect(storageTraces()->queries($with)[0]->bindings)->toBe(['[REDACTED]', 7])
        ->and(storageTraces()->queries($without)[0]->bindings)->toBeNull()
        ->and(DB::table('sherlock_queries')->where('trace_id', $without)->value('bindings'))->toBeNull();
});

it('returns the queries of a trace in execution order', function (): void {
    $id = storageTraces()->store(F::recorded(F::traceData([
        F::query('select 2', 5.0, fingerprint: 'b', offsetMs: 20.0),
        F::query('select 1', 3.0, fingerprint: 'a', offsetMs: 10.0),
    ])));

    $queries = storageTraces()->queries($id);

    expect($queries)->toHaveCount(2)
        ->and($queries[0])->toBeInstanceOf(StoredQuery::class)
        ->and($queries[0]->fingerprint)->toBe('a')
        ->and($queries[0]->normalizedSql)->toBe('select 1')
        ->and($queries[0]->offsetMs)->toBe(10.0)
        ->and($queries[0]->traceId)->toBe($id)
        ->and($queries[0]->connection)->toBe('mysql')
        ->and($queries[0]->file)->toBe('app/Http/Controllers/UserController.php')
        ->and($queries[0]->line)->toBe(42)
        ->and($queries[1]->fingerprint)->toBe('b');
});

it('normalizes and fingerprints queries the analysis did not normalize', function (): void {
    $id = storageTraces()->store(F::recorded(F::traceData([F::query("select * from users where email = 'a@b.c'", fingerprint: null)])));

    $query = storageTraces()->queries($id)[0];

    expect($query->normalizedSql)->not->toContain('a@b.c')
        ->and($query->fingerprint)->toHaveLength(32);
});

it('caps stored queries per trace keeping every fingerprint and the slowest ones', function (): void {
    config()->set('sherlock.storage.max_queries_per_trace', 3);
    app()->forgetInstance(TraceRepository::class);

    $id = storageTraces()->store(F::recorded(F::traceData([
        F::query('q1', 1.0, fingerprint: 'n1', offsetMs: 1),
        F::query('q1', 2.0, fingerprint: 'n1', offsetMs: 2),
        F::query('q1', 3.0, fingerprint: 'n1', offsetMs: 3),
        F::query('q2', 0.5, fingerprint: 'rare', offsetMs: 4),
        F::query('q3', 900.0, fingerprint: 'slow', offsetMs: 5),
    ])));

    $stored = storageTraces()->queries($id);

    expect(array_map(fn ($q) => [$q->fingerprint, $q->durationMs], $stored))
        ->toBe([['n1', 3.0], ['rare', 0.5], ['slow', 900.0]])
        ->and(storageTraces()->find($id)->dbCount)->toBe(5);
});

it('paginates traces with filters and sorting', function (): void {
    $now = CarbonImmutable::parse('2026-10-20 12:00:00');
    storageTraces()->store(F::recorded(F::traceData(name: 'GET /a', durationMs: 100, startedAt: $now->subHours(3))));
    storageTraces()->store(F::recorded(F::traceData(name: 'GET /b', durationMs: 900, startedAt: $now->subHours(2)), bottleneck: Bottleneck::Application));
    $jobId = storageTraces()->store(F::recorded(F::traceData(type: EntryType::Job, name: 'App\\Jobs\\Sync', durationMs: 500, startedAt: $now->subHour())));

    $page = storageTraces()->paginate(new TraceFilter, 2, 1);

    expect($page)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($page->total())->toBe(3)
        ->and($page->lastPage())->toBe(2)
        ->and($page->items())->toHaveCount(2)
        ->and($page->items()[0]->id)->toBe($jobId)
        ->and(storageTraces()->paginate(new TraceFilter, 2, 2)->items())->toHaveCount(1)
        ->and(storageTraces()->paginate(new TraceFilter(sort: 'duration'))->items()[0]->name)->toBe('GET /b')
        ->and(storageTraces()->paginate(new TraceFilter(type: EntryType::Job))->total())->toBe(1)
        ->and(storageTraces()->paginate(new TraceFilter(name: 'GET /a'))->total())->toBe(1)
        ->and(storageTraces()->paginate(new TraceFilter(bottleneck: Bottleneck::Application))->total())->toBe(1)
        ->and(storageTraces()->paginate(new TraceFilter(minDurationMs: 500))->total())->toBe(2)
        ->and(storageTraces()->paginate(new TraceFilter(since: $now->subMinutes(90)))->total())->toBe(1);
});

it('filters traces by issue through the issue links', function (): void {
    $a = storageTraces()->store(F::recorded());
    storageTraces()->store(F::recorded());
    DB::table('sherlock_issue_traces')->insert(['issue_id' => 9, 'trace_id' => $a, 'created_at' => now()]);

    $page = storageTraces()->paginate(new TraceFilter(issueId: 9));

    expect($page->total())->toBe(1)
        ->and($page->items()[0]->id)->toBe($a);
});

it('aggregates the slowest entries in SQL', function (): void {
    $since = CarbonImmutable::now()->subDay();
    storageTraces()->store(F::recorded(F::traceData(name: 'GET /slow', durationMs: 1000, queries: [F::query(timeMs: 100)])));
    storageTraces()->store(F::recorded(F::traceData(name: 'GET /slow', durationMs: 3000, queries: [F::query(timeMs: 300), F::query(timeMs: 1)])));
    storageTraces()->store(F::recorded(F::traceData(name: 'GET /fast', durationMs: 50)));
    storageTraces()->store(F::recorded(F::traceData(type: EntryType::Job, name: 'Job', durationMs: 9000)));
    storageTraces()->store(F::recorded(F::traceData(name: 'GET /old', durationMs: 99999, startedAt: CarbonImmutable::now()->subDays(3))));

    $stats = storageTraces()->slowestEntries($since, 10, EntryType::Request);

    expect($stats)->toHaveCount(2)
        ->and($stats[0]->name)->toBe('GET /slow')
        ->and($stats[0]->type)->toBe(EntryType::Request)
        ->and($stats[0]->count)->toBe(2)
        ->and($stats[0]->avgMs)->toBe(2000.0)
        ->and($stats[0]->maxMs)->toBe(3000.0)
        ->and($stats[0]->avgDbMs)->toEqualWithDelta(200.5, 0.01)
        ->and($stats[0]->avgQueries)->toEqualWithDelta(1.5, 0.01)
        ->and(storageTraces()->slowestEntries($since)[0]->name)->toBe('Job')
        ->and(storageTraces()->slowestEntries($since, 1))->toHaveCount(1);
});

it('aggregates top queries by total, count and average', function (): void {
    storageTraces()->store(F::recorded(F::traceData([
        F::query('select a', 10.0, fingerprint: 'a'),
        F::query('select a', 10.0, fingerprint: 'a'),
        F::query('select a', 10.0, fingerprint: 'a'),
        F::query('select b', 25.0, fingerprint: 'b'),
        F::query('select c', 50.0, fingerprint: 'c'),
    ])));
    storageTraces()->store(F::recorded(F::traceData([F::query('select old', 1000.0, fingerprint: 'old')], startedAt: CarbonImmutable::now()->subDays(5))));

    $since = CarbonImmutable::now()->subDay();
    $byTotal = storageTraces()->topQueries($since);

    expect($byTotal)->toHaveCount(3)
        ->and($byTotal[0])->toBeInstanceOf(QueryStat::class)
        ->and($byTotal[0]->fingerprint)->toBe('c')
        ->and($byTotal[1]->fingerprint)->toBe('a')
        ->and($byTotal[1]->count)->toBe(3)
        ->and($byTotal[1]->totalMs)->toBe(30.0)
        ->and($byTotal[1]->avgMs)->toBe(10.0)
        ->and($byTotal[1]->maxMs)->toBe(10.0)
        ->and($byTotal[1]->normalizedSql)->toBe('select a')
        ->and(storageTraces()->topQueries($since, 'count')[0]->fingerprint)->toBe('a')
        ->and(storageTraces()->topQueries($since, 'avg')[0]->fingerprint)->toBe('c')
        ->and(storageTraces()->topQueries($since, 'total', 1))->toHaveCount(1);
});

it('computes the bottleneck distribution and counts', function (): void {
    storageTraces()->store(F::recorded(bottleneck: Bottleneck::Database));
    storageTraces()->store(F::recorded(bottleneck: Bottleneck::Database));
    storageTraces()->store(F::recorded(F::traceData(type: EntryType::Job, name: 'J'), bottleneck: Bottleneck::ExternalService));
    storageTraces()->store(F::recorded(F::traceData(startedAt: CarbonImmutable::now()->subDays(9)), bottleneck: Bottleneck::Cache));

    $since = CarbonImmutable::now()->subDay();

    expect(storageTraces()->bottleneckDistribution($since))->toBe(['database' => 2, 'external_service' => 1])
        ->and(storageTraces()->countSince($since))->toBe(3)
        ->and(storageTraces()->countSince($since, EntryType::Job))->toBe(1);
});

it('prunes old traces with their queries and issue links in chunks', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00'));

    $old = [];
    for ($i = 0; $i < 5; $i++) {
        $old[] = storageTraces()->store(F::recorded(F::traceData([F::query(), F::query()], startedAt: CarbonImmutable::now()->subDays(10))));
    }
    $fresh = storageTraces()->store(F::recorded(F::traceData([F::query()])));
    DB::table('sherlock_issue_traces')->insert([
        ['issue_id' => 1, 'trace_id' => $old[0], 'created_at' => now()],
        ['issue_id' => 1, 'trace_id' => $fresh, 'created_at' => now()],
    ]);

    $deleted = storageTraces()->pruneOlderThan(CarbonImmutable::now()->subDays(7), 2);

    expect($deleted)->toBe(5)
        ->and(DB::table('sherlock_traces')->pluck('id')->all())->toBe([$fresh])
        ->and(DB::table('sherlock_queries')->count())->toBe(1)
        ->and(DB::table('sherlock_issue_traces')->pluck('trace_id')->all())->toBe([$fresh])
        ->and(storageTraces()->pruneOlderThan(CarbonImmutable::now()->subDays(7)))->toBe(0);
});
