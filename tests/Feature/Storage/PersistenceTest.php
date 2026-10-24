<?php

declare(strict_types=1);

use Anabil\Sherlock\Contracts\IssueService;
use Anabil\Sherlock\Contracts\PersistenceStrategy;
use Anabil\Sherlock\Contracts\TraceRecorder;
use Anabil\Sherlock\Contracts\TraceRepository;
use Anabil\Sherlock\Data\RecordedTrace;
use Anabil\Sherlock\Events\IssueDetected;
use Anabil\Sherlock\Events\TraceRecorded;
use Anabil\Sherlock\Jobs\PersistTraceJob;
use Anabil\Sherlock\Sherlock;
use Anabil\Sherlock\Storage\Persistence\AfterResponsePersistence;
use Anabil\Sherlock\Storage\Persistence\QueuedPersistence;
use Anabil\Sherlock\Storage\Persistence\TraceWriter;
use Anabil\Sherlock\Storage\Support\QuietReporter;
use Anabil\Sherlock\Storage\Support\RecordingPauser;
use Anabil\Sherlock\Support\Null\NullTraceRepository;
use Anabil\Sherlock\Tests\Feature\Storage\StorageFixtures as F;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Psr\Log\AbstractLogger;

/** Rebuild the storage singletons (after config changes or fakes). */
function freshPersistence(): PersistenceStrategy
{
    foreach ([PersistenceStrategy::class, TraceWriter::class, IssueService::class, RecordingPauser::class, AfterResponsePersistence::class, QueuedPersistence::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    return app(PersistenceStrategy::class);
}

function memoryLogger(): AbstractLogger
{
    return new class extends AbstractLogger
    {
        /** @var list<array{string, string}> */
        public array $records = [];

        public function log($level, Stringable|string $message, array $context = []): void
        {
            $this->records[] = [(string) $level, (string) $message];
        }
    };
}

beforeEach(fn () => QuietReporter::flush());

it('selects the persistence strategy from config', function (): void {
    config()->set('sherlock.storage.persistence', 'after_response');
    expect(freshPersistence())->toBeInstanceOf(AfterResponsePersistence::class);

    config()->set('sherlock.storage.persistence', 'queue');
    expect(freshPersistence())->toBeInstanceOf(QueuedPersistence::class);
});

it('writes after the response: trace, issues, then TraceRecorded', function (): void {
    Event::fake([TraceRecorded::class, IssueDetected::class]);
    config()->set('sherlock.storage.persistence', 'after_response');
    $recorded = F::recorded(findings: [F::finding()]);

    freshPersistence()->persist($recorded);

    expect(DB::table('sherlock_traces')->where('id', $recorded->trace->id)->exists())->toBeTrue()
        ->and(DB::table('sherlock_issues')->count())->toBe(1)
        ->and(DB::table('sherlock_issue_traces')->value('trace_id'))->toBe($recorded->trace->id);
    Event::assertDispatched(TraceRecorded::class, fn (TraceRecorded $e): bool => $e->traceId === $recorded->trace->id && $e->trace === $recorded);
    Event::assertDispatched(IssueDetected::class);
});

it('is reached through the core TraceRecorder', function (): void {
    config()->set('sherlock.storage.persistence', 'after_response');
    freshPersistence();
    app()->forgetInstance(TraceRecorder::class);

    $trace = F::traceData([F::query()]);
    app(TraceRecorder::class)->record($trace);

    expect(DB::table('sherlock_traces')->where('id', $trace->id)->exists())->toBeTrue()
        ->and(DB::table('sherlock_queries')->where('trace_id', $trace->id)->count())->toBe(1);
});

it('queues a PersistTraceJob on the configured queue', function (): void {
    Queue::fake();
    config()->set('sherlock.storage.persistence', 'queue');
    config()->set('sherlock.queue.connection', 'redis');
    config()->set('sherlock.queue.name', 'sherlock');
    $recorded = F::recorded();

    freshPersistence()->persist($recorded);

    Queue::assertPushed(PersistTraceJob::class, fn (PersistTraceJob $job): bool => $job->trace === $recorded
        && $job->connection === 'redis'
        && $job->queue === 'sherlock');
    expect(DB::table('sherlock_traces')->count())->toBe(0);
});

it('persists through a real (sync) queue run', function (): void {
    config()->set('queue.default', 'sync');
    config()->set('sherlock.storage.persistence', 'queue');
    config()->set('sherlock.queue.connection', 'sync');
    $recorded = F::recorded(findings: [F::finding()]);

    freshPersistence()->persist($recorded);

    expect(DB::table('sherlock_traces')->where('id', $recorded->trace->id)->exists())->toBeTrue()
        ->and(DB::table('sherlock_queries')->count())->toBe(1)
        ->and(DB::table('sherlock_issues')->count())->toBe(1);
});

it('serializes the RecordedTrace losslessly for the queue', function (): void {
    $recorded = F::recorded(
        F::traceData([F::query()], logs: [F::exception()], httpCalls: [F::httpCall()]),
        [F::finding()],
        explainPlans: ['fp-users' => F::plan()],
    );

    /** @var PersistTraceJob $job */
    $job = unserialize(serialize(new PersistTraceJob($recorded)));

    expect($job->trace)->toBeInstanceOf(RecordedTrace::class)
        ->and($job->trace)->toEqual($recorded)
        ->and($job->trace->trace->toArray())->toBe($recorded->trace->toArray());
});

it('swallows failures and logs each distinct error once', function (): void {
    $repository = new class implements TraceRepository
    {
        public int $calls = 0;

        public function store(RecordedTrace $trace): string
        {
            $this->calls++;

            throw new RuntimeException('database is down');
        }

        public function find(string $id): ?Anabil\Sherlock\Data\StoredTrace
        {
            return null;
        }

        public function queries(string $traceId): array
        {
            return [];
        }

        public function paginate(Anabil\Sherlock\Data\TraceFilter $filter, int $perPage = 25, int $page = 1): Illuminate\Contracts\Pagination\LengthAwarePaginator
        {
            return (new NullTraceRepository)->paginate($filter);
        }

        public function slowestEntries(DateTimeImmutable $since, int $limit = 10, ?Anabil\Sherlock\Enums\EntryType $type = null): array
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

        public function countSince(DateTimeImmutable $since, ?Anabil\Sherlock\Enums\EntryType $type = null): int
        {
            return 0;
        }

        public function pruneOlderThan(DateTimeImmutable $before, int $chunkSize = 1000): int
        {
            return 0;
        }

        public function releases(int $limit = 20): array
        {
            return [];
        }

        public function findRelease(string $release): ?Anabil\Sherlock\Data\ReleaseRecord
        {
            return null;
        }

        public function entryStatsForRelease(string $release, ?Anabil\Sherlock\Enums\EntryType $type = null, int $limit = 50, ?Anabil\Sherlock\Enums\RecordReason $reason = null): array
        {
            return [];
        }
    };
    $logger = memoryLogger();
    $writer = new TraceWriter($repository, app(IssueService::class), app(Dispatcher::class), new RecordingPauser, new QuietReporter($logger));
    $persistence = new AfterResponsePersistence($writer);

    $persistence->persist(F::recorded());
    $persistence->persist(F::recorded());

    expect($repository->calls)->toBe(2)
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0][0])->toBe('warning')
        ->and($logger->records[0][1])->toContain('database is down');
});

it('keeps the stored trace when the issue upsert fails', function (): void {
    $issues = new class implements IssueService
    {
        public function recordFindings(string $traceId, RecordedTrace $trace): array
        {
            throw new RuntimeException('issues broke');
        }

        public function changeStatus(int|string $issueId, Anabil\Sherlock\Enums\IssueStatus $status): void {}
    };
    Event::fake([TraceRecorded::class]);
    $logger = memoryLogger();
    $writer = new TraceWriter(app(TraceRepository::class), $issues, app(Dispatcher::class), new RecordingPauser, new QuietReporter($logger));
    $recorded = F::recorded();

    expect($writer->write($recorded))->toBe($recorded->trace->id)
        ->and(DB::table('sherlock_traces')->count())->toBe(1)
        ->and($logger->records[0][1])->toContain('issues broke');
    Event::assertDispatched(TraceRecorded::class);
});

it('runs storage work through the capture pause hook when one exists', function (): void {
    $hook = new class
    {
        public int $paused = 0;

        public bool $inside = false;

        public function pause(Closure $callback): mixed
        {
            $this->paused++;
            $this->inside = true;

            try {
                return $callback();
            } finally {
                $this->inside = false;
            }
        }
    };
    app()->instance(Sherlock::class, $hook);
    config()->set('sherlock.storage.persistence', 'after_response');

    $insideDuringInsert = null;
    DB::listen(function ($query) use ($hook, &$insideDuringInsert): void {
        if (str_contains($query->sql, 'insert into `sherlock_traces`')) {
            $insideDuringInsert = $hook->inside;
        }
    });

    freshPersistence()->persist(F::recorded());

    expect($hook->paused)->toBe(1)
        ->and($insideDuringInsert)->toBeTrue()
        ->and(DB::table('sherlock_traces')->count())->toBe(1);
});
