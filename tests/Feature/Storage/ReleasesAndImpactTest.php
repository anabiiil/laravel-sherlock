<?php

declare(strict_types=1);

use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Contracts\TraceRepository;
use Anabil\Sherlock\Data\AnalysisResult;
use Anabil\Sherlock\Data\EntryDelta;
use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Data\IssueFilter;
use Anabil\Sherlock\Data\LazyLoadRecord;
use Anabil\Sherlock\Data\RecordedTrace;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Data\TraceFilter;
use Anabil\Sherlock\Data\Verdict;
use Anabil\Sherlock\Enums\Bottleneck;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\IssueStatus;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\RecordReason;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Releases\ReleaseComparator;
use Anabil\Sherlock\Storage\Repositories\DatabaseTraceRepository;
use Anabil\Sherlock\Tests\Feature\Storage\StorageFixtures as F;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function releaseTrace(string $release, string $name, float $durationMs, RecordReason $reason = RecordReason::Sample, ?CarbonImmutable $at = null, EntryType $type = EntryType::Request): RecordedTrace
{
    $trace = new TraceData(
        id: (string) Str::ulid(),
        type: $type,
        name: $name,
        startedAt: $at ?? CarbonImmutable::now(),
        durationMs: $durationMs,
        method: $type === EntryType::Request ? 'GET' : null,
        queries: [F::query(timeMs: $durationMs / 4)],
        recordReasons: [$reason],
        release: $release,
        cpuMs: 12.5,
        loadAvg: 1.25,
        cpuCores: 4,
        memoryFreeMb: 2048,
        queueWaitMs: $type === EntryType::Job ? 1500.0 : null,
        lazyLoads: [new LazyLoadRecord('App\\Models\\User', 'posts', 3, 'posts')],
    );

    return new RecordedTrace($trace, new AnalysisResult([], new Verdict(Bottleneck::Database, 'db')), $reason, false);
}

function impactFinding(float $impactMs, string $fingerprint = 'issue-impact', Severity $severity = Severity::Warning): Finding
{
    return new Finding(IssueType::NPlusOne, $severity, 'N+1 on posts', 'd', $fingerprint, impactMs: $impactMs);
}

it('stores the server signals, release and lazy loads of a trace and reads them back', function (): void {
    $recorded = releaseTrace('abc1234', 'GET /users', 400.0, type: EntryType::Job);
    $id = app(TraceRepository::class)->store($recorded);

    $stored = app(TraceRepository::class)->find($id);

    expect($stored?->release)->toBe('abc1234')
        ->and($stored?->cpuMs)->toBe(12.5)
        ->and($stored?->loadAvg)->toBe(1.25)
        ->and($stored?->cpuCores)->toBe(4)
        ->and($stored?->loadPerCore())->toBe(0.3125)
        ->and($stored?->memoryFreeMb)->toBe(2048)
        ->and($stored?->queueWaitMs)->toBe(1500.0)
        ->and($stored?->details['lazy_loads'][0])->toEqual(['model' => 'App\\Models\\User', 'relation' => 'posts', 'count' => 3, 'table' => 'posts'])
        ->and(app(TraceRepository::class)->paginate(new TraceFilter(release: 'abc1234'))->total())->toBe(1)
        ->and(app(TraceRepository::class)->paginate(new TraceFilter(release: 'other'))->total())->toBe(0);
});

it('upserts a release at most once per process, then refreshes last_seen_at at most every 5 minutes', function (): void {
    $repository = app(TraceRepository::class);
    $first = CarbonImmutable::parse('2026-10-20 10:00:00');

    DB::enableQueryLog();
    $repository->store(releaseTrace('r1', 'GET /a', 100.0, at: $first));
    $repository->store(releaseTrace('r1', 'GET /a', 100.0, at: $first->addMinutes(1)));
    $repository->store(releaseTrace('r1', 'GET /a', 100.0, at: $first->addMinutes(2)));
    $releaseQueries = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'sherlock_releases'))->count();
    DB::disableQueryLog();

    $rows = DB::table('sherlock_releases')->get();

    expect($releaseQueries)->toBe(1)
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]->first_seen_at)->toBe('2026-10-20 10:00:00')
        ->and($rows[0]->last_seen_at)->toBe('2026-10-20 10:00:00');

    // Another process (fresh repository) refreshes last_seen_at, never first_seen_at.
    app()->forgetInstance(TraceRepository::class);
    app(TraceRepository::class)->store(releaseTrace('r1', 'GET /a', 100.0, at: $first->addHour()));

    $row = DB::table('sherlock_releases')->first();

    expect($row?->first_seen_at)->toBe('2026-10-20 10:00:00')
        ->and($row?->last_seen_at)->toBe('2026-10-20 11:00:00');
});

it('lists releases most recently deployed first', function (): void {
    $repository = app(TraceRepository::class);
    $repository->store(releaseTrace('old', 'GET /a', 100.0, at: CarbonImmutable::parse('2026-10-01 10:00:00')));
    $repository->store(releaseTrace('new', 'GET /a', 100.0, at: CarbonImmutable::parse('2026-10-10 10:00:00')));

    $releases = $repository->releases(10);

    expect(array_map(fn ($r) => $r->release, $releases))->toBe(['new', 'old'])
        ->and($releases[0]->firstSeenAt->format('Y-m-d'))->toBe('2026-10-10')
        ->and($repository->findRelease('old')?->release)->toBe('old')
        ->and($repository->findRelease('missing'))->toBeNull()
        ->and($repository->releases(1))->toHaveCount(1);
});

it('aggregates per-entry statistics of a release, optionally sampled traces only', function (): void {
    $repository = app(TraceRepository::class);
    $repository->store(releaseTrace('r1', 'GET /a', 100.0));
    $repository->store(releaseTrace('r1', 'GET /a', 300.0));
    $repository->store(releaseTrace('r1', 'GET /a', 2000.0, RecordReason::Threshold));
    $repository->store(releaseTrace('r1', 'App\\Jobs\\Sync', 50.0, type: EntryType::Job));
    $repository->store(releaseTrace('r2', 'GET /a', 999.0));

    $all = $repository->entryStatsForRelease('r1');
    $sampled = $repository->entryStatsForRelease('r1', EntryType::Request, 10, RecordReason::Sample);

    expect($all)->toHaveCount(2)
        ->and($all[0]->name)->toBe('GET /a')
        ->and($all[0]->count)->toBe(3)
        ->and($all[0]->avgMs)->toBe(800.0)
        ->and($all[0]->maxMs)->toBe(2000.0)
        ->and($sampled)->toHaveCount(1)
        ->and($sampled[0]->count)->toBe(2)
        ->and($sampled[0]->avgMs)->toBe(200.0)
        ->and($sampled[0]->avgDbMs)->toBe(50.0);
});

it('compares two releases per entry, preferring sampled traces when there are enough', function (): void {
    $repository = app(TraceRepository::class);
    $prev = CarbonImmutable::parse('2026-10-01 10:00:00');
    $curr = CarbonImmutable::parse('2026-10-10 10:00:00');

    foreach (range(1, 3) as $i) {
        // Enough samples on both sides: the threshold outliers are ignored.
        $repository->store(releaseTrace('v1', 'GET /orders', 100.0, at: $prev));
        $repository->store(releaseTrace('v2', 'GET /orders', 150.0, at: $curr));
        // Few samples: every persisted trace is used (biased basis).
        $repository->store(releaseTrace('v1', 'GET /users', 400.0, RecordReason::Threshold, $prev));
        $repository->store(releaseTrace('v2', 'GET /users', 200.0, RecordReason::Threshold, $curr));
        $repository->store(releaseTrace('v1', 'GET /same', 100.0, at: $prev));
        $repository->store(releaseTrace('v2', 'GET /same', 102.0, at: $curr));
    }
    $repository->store(releaseTrace('v2', 'GET /orders', 5000.0, RecordReason::Threshold, $curr));
    $repository->store(releaseTrace('v2', 'GET /new', 80.0, at: $curr));
    $repository->store(releaseTrace('v1', 'GET /removed', 80.0, at: $prev));

    $issues = app(IssueRepository::class);
    $issues->recordOccurrence(impactFinding(10.0, 'issue-old'), [], $prev, null, null, 'v1');
    $issues->recordOccurrence(impactFinding(20.0, 'issue-new'), [], $curr, null, null, 'v2');

    $comparison = (new ReleaseComparator($repository, $issues, minSamples: 3))->compare('v2', 'v1');
    $byName = collect($comparison->entries)->keyBy('name');

    expect($comparison->currentRelease?->release)->toBe('v2')
        ->and($comparison->previousRelease?->release)->toBe('v1')
        ->and($byName['GET /orders']->status)->toBe(EntryDelta::SLOWER)
        ->and($byName['GET /orders']->basis)->toBe(EntryDelta::BASIS_SAMPLE)
        ->and($byName['GET /orders']->deltaAvgMs)->toBe(50.0)
        ->and($byName['GET /orders']->deltaRatio)->toBe(0.5)
        ->and($byName['GET /users']->status)->toBe(EntryDelta::FASTER)
        ->and($byName['GET /users']->basis)->toBe(EntryDelta::BASIS_ALL)
        ->and($byName['GET /same']->status)->toBe(EntryDelta::UNCHANGED)
        ->and($byName['GET /new']->status)->toBe(EntryDelta::NEW)
        ->and($byName['GET /new']->previous)->toBeNull()
        ->and($byName['GET /removed']->status)->toBe(EntryDelta::GONE)
        ->and(array_map(fn (EntryDelta $d): string => $d->status, $comparison->entries))
        ->toBe(['slower', 'new', 'faster', 'unchanged', 'gone'])
        ->and(array_map(fn ($i) => $i->fingerprint, $comparison->newIssues))->toBe(['issue-new'])
        ->and(array_map(fn ($i) => $i->fingerprint, $comparison->goneIssues))->toBe(['issue-old'])
        ->and($comparison->slower())->toHaveCount(1)
        ->and($comparison->faster())->toHaveCount(1)
        ->and($comparison->hasRegressions())->toBeTrue()
        ->and($byName['GET /orders']->toArray()['current']['count'])->toBe(3);

    expect(app(ReleaseComparator::class)->compareLatest()?->current)->toBe('v2');
});

it('reports issues that regressed while a release ran', function (): void {
    $issues = app(IssueRepository::class);
    app(TraceRepository::class)->store(releaseTrace('v2', 'GET /a', 10.0, at: CarbonImmutable::parse('2026-10-10 10:00:00')));

    $outcome = $issues->recordOccurrence(impactFinding(5.0), [], CarbonImmutable::parse('2026-10-01 10:00:00'), null, null, 'v1');
    $issues->updateStatus($outcome->issueId, IssueStatus::Resolved);
    $issues->recordOccurrence(impactFinding(5.0), [], CarbonImmutable::parse('2026-10-11 10:00:00'), null, null, 'v2');

    $regressed = $issues->regressedInRelease('v2');

    expect($regressed)->toHaveCount(1)
        ->and($regressed[0]->firstSeenRelease)->toBe('v1')
        ->and($regressed[0]->lastSeenRelease)->toBe('v2')
        ->and($issues->regressedInRelease('v1'))->toBe([])
        ->and($issues->lastSeenInRelease('v1'))->toBe([])
        ->and($issues->firstSeenInRelease('v1'))->toHaveCount(1);
});

it('accumulates impact_ms atomically in the occurrence update, with no extra query', function (): void {
    $issues = app(IssueRepository::class);
    $now = CarbonImmutable::parse('2026-10-20 10:00:00');

    $outcome = $issues->recordOccurrence(impactFinding(120.5), [], $now, null, null, 'r1');

    DB::enableQueryLog();
    $issues->recordOccurrence(impactFinding(79.5), [], $now->addMinute(), null, null, 'r2');
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $updates = array_values(array_filter($log, fn (array $q): bool => str_starts_with(strtolower($q['query']), 'update')));
    $issue = $issues->find($outcome->issueId);

    expect($updates)->toHaveCount(1)
        ->and($updates[0]['query'])->toContain('`impact_ms` = `impact_ms` + 79.5')
        ->and($updates[0]['query'])->toContain('`occurrences` = occurrences + 1')
        ->and($issue?->impactMs)->toBe(200.0)
        ->and($issue?->occurrences)->toBe(2)
        ->and($issue?->firstSeenRelease)->toBe('r1')
        ->and($issue?->lastSeenRelease)->toBe('r2')
        ->and($issue?->toArray())->toMatchArray(['impact_ms' => 200.0, 'first_seen_release' => 'r1', 'last_seen_release' => 'r2']);

    // No impact: a plain update, the total is unchanged.
    $issues->recordOccurrence(impactFinding(0.0), [], $now->addMinutes(2));

    expect($issues->find($outcome->issueId)?->impactMs)->toBe(200.0)
        ->and($issues->find($outcome->issueId)?->lastSeenRelease)->toBe('r2');
});

it('sorts and ranks issues by impact', function (): void {
    $issues = app(IssueRepository::class);
    $now = CarbonImmutable::now();

    $issues->recordOccurrence(impactFinding(50.0, 'a'), [], $now, null, null, 'r1');
    $issues->recordOccurrence(impactFinding(500.0, 'b'), [], $now, null, null, 'r2');
    $issues->recordOccurrence(impactFinding(5.0, 'c'), [], $now, null, null, 'r2');
    $issues->recordOccurrence(impactFinding(0.0, 'd'), [], $now);
    $issues->recordOccurrence(impactFinding(1000.0, 'old'), [], $now->subDays(30));

    $sorted = $issues->paginate(new IssueFilter(sort: 'impact'));
    $filtered = $issues->paginate(new IssueFilter(sort: 'impact', release: 'r2'));

    expect(array_map(fn ($i) => $i->fingerprint, $sorted->items()))->toBe(['old', 'b', 'a', 'c', 'd'])
        ->and(array_map(fn ($i) => $i->fingerprint, $filtered->items()))->toBe(['b', 'c'])
        ->and($filtered->total())->toBe(2)
        ->and(array_map(fn ($i) => $i->fingerprint, $issues->topByImpact($now->subDay(), 10)))->toBe(['b', 'a', 'c'])
        ->and($issues->topByImpact($now->subDay(), 1))->toHaveCount(1);
});

it('links an issue to the release of its trace through the issue service', function (): void {
    $recorded = releaseTrace('deploy-7', 'GET /a', 100.0);
    $recorded = new RecordedTrace($recorded->trace, new AnalysisResult([impactFinding(33.0)], new Verdict(Bottleneck::Database, 'db')), $recorded->reason, false);

    app(Anabil\Sherlock\Storage\Persistence\TraceWriter::class)->write($recorded);

    $issue = app(IssueRepository::class)->findByFingerprint('issue-impact');

    expect($issue?->firstSeenRelease)->toBe('deploy-7')
        ->and($issue?->impactMs)->toBe(33.0)
        ->and($issue?->context['trace']['release'])->toBe('deploy-7')
        ->and($issue?->context['trace']['load_avg'])->toBe(1.25)
        ->and($issue?->context['lazy_loads'][0]['relation'])->toBe('posts');
});

it('keeps the repository bound to the database implementation', function (): void {
    expect(app(TraceRepository::class))->toBeInstanceOf(DatabaseTraceRepository::class);
});

it('adds the signal / release columns and the comparison indexes, and rolls back cleanly', function (): void {
    $indexes = fn (string $table): array => collect(Illuminate\Support\Facades\Schema::getIndexes($table))
        ->map(fn (array $index): string => ($index['unique'] ? 'unique:' : '').implode(',', $index['columns']))
        ->all();
    $schema = Illuminate\Support\Facades\Schema::getFacadeRoot();

    expect($schema->hasColumns('sherlock_traces', ['release', 'cpu_ms', 'load_avg', 'cpu_cores', 'memory_free_mb', 'queue_wait_ms']))->toBeTrue()
        ->and($schema->hasColumns('sherlock_issues', ['first_seen_release', 'last_seen_release', 'impact_ms']))->toBeTrue()
        ->and($schema->hasColumns('sherlock_releases', ['id', 'release', 'first_seen_at', 'last_seen_at']))->toBeTrue()
        ->and($indexes('sherlock_traces'))->toContain('release,type,name', 'release,created_at')
        ->and($indexes('sherlock_issues'))->toContain('first_seen_release', 'last_seen_release', 'impact_ms')
        ->and($indexes('sherlock_releases'))->toContain('unique:release', 'first_seen_at');

    $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_24_000000_add_sherlock_server_signals_and_releases.php';
    $migration->down();

    expect($schema->hasTable('sherlock_releases'))->toBeFalse()
        ->and($schema->hasColumn('sherlock_traces', 'release'))->toBeFalse()
        ->and($schema->hasColumn('sherlock_issues', 'impact_ms'))->toBeFalse()
        ->and($schema->hasColumn('sherlock_traces', 'duration_ms'))->toBeTrue();

    // Idempotent rollback, then back up for the rest of the suite.
    $migration->down();
    $migration->up();

    expect($schema->hasColumn('sherlock_issues', 'impact_ms'))->toBeTrue();
});
