<?php

declare(strict_types=1);

use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Contracts\IssueService;
use Anabil\Sherlock\Data\IssueOutcome;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\IssueStatus;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\OccurrenceType;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Events\IssueDetected;
use Anabil\Sherlock\Events\IssueRecurred;
use Anabil\Sherlock\Events\IssueRegressed;
use Anabil\Sherlock\Issues\DefaultIssueService;
use Anabil\Sherlock\Jobs\AnalyzeIssueJob;
use Anabil\Sherlock\Jobs\NotifyIssueJob;
use Anabil\Sherlock\Tests\Feature\Storage\StorageFixtures as F;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function issueService(): DefaultIssueService
{
    return app(IssueService::class);
}

/** Fake the issue events; the service singleton is rebuilt so it gets the fake dispatcher. */
function fakeIssueEvents(): void
{
    Event::fake([IssueDetected::class, IssueRegressed::class, IssueRecurred::class]);
    app()->forgetInstance(IssueService::class);
}

it('is bound to the default issue service', function (): void {
    expect(app(IssueService::class))->toBeInstanceOf(DefaultIssueService::class);
});

it('dispatches IssueDetected for new issues and attaches the trace', function (): void {
    fakeIssueEvents();

    $outcomes = issueService()->recordFindings('trace-1', F::recorded(findings: [F::finding()]));

    expect($outcomes)->toHaveCount(1)
        ->and($outcomes[0])->toBeInstanceOf(IssueOutcome::class)
        ->and($outcomes[0]->occurrence)->toBe(OccurrenceType::New)
        ->and(app(IssueRepository::class)->traceIds($outcomes[0]->issueId))->toBe(['trace-1']);

    Event::assertDispatched(IssueDetected::class, fn (IssueDetected $e): bool => $e->outcome->issueId === $outcomes[0]->issueId);
    Event::assertNotDispatched(IssueRegressed::class);
    Event::assertNotDispatched(IssueRecurred::class);
});

it('dispatches IssueRecurred (not IssueDetected) for repeats', function (): void {
    issueService()->recordFindings('trace-1', F::recorded(findings: [F::finding()]));
    fakeIssueEvents();

    $outcomes = issueService()->recordFindings('trace-2', F::recorded(findings: [F::finding()]));

    expect($outcomes[0]->occurrence)->toBe(OccurrenceType::Recurring)
        ->and($outcomes[0]->occurrences)->toBe(2);
    Event::assertDispatched(IssueRecurred::class, fn (IssueRecurred $e): bool => $e->outcome->occurrences === 2);
    Event::assertNotDispatched(IssueDetected::class);
});

it('dispatches IssueRegressed when a resolved issue comes back', function (): void {
    $id = issueService()->recordFindings('trace-1', F::recorded(findings: [F::finding()]))[0]->issueId;
    issueService()->changeStatus($id, IssueStatus::Resolved);
    expect(app(IssueRepository::class)->find($id)->status)->toBe(IssueStatus::Resolved);

    fakeIssueEvents();
    $outcomes = issueService()->recordFindings('trace-2', F::recorded(findings: [F::finding()]));

    expect($outcomes[0]->occurrence)->toBe(OccurrenceType::Regressed);
    Event::assertDispatched(IssueRegressed::class);
    Event::assertNotDispatched(IssueDetected::class);
    Event::assertNotDispatched(IssueRecurred::class);
});

it('stays silent for ignored issues and does not link traces', function (): void {
    $id = issueService()->recordFindings('trace-1', F::recorded(findings: [F::finding()]))[0]->issueId;
    issueService()->changeStatus($id, IssueStatus::Ignored);

    fakeIssueEvents();
    $outcomes = issueService()->recordFindings('trace-2', F::recorded(findings: [F::finding()]));

    expect($outcomes[0]->isIgnored())->toBeTrue()
        ->and(app(IssueRepository::class)->traceIds($id))->toBe(['trace-1'])
        ->and(app(IssueRepository::class)->find($id)->occurrences)->toBe(2);
    Event::assertNothingDispatched();
});

it('records each issue fingerprint once per trace, keeping the most severe finding', function (): void {
    $outcomes = issueService()->recordFindings('trace-1', F::recorded(findings: [
        F::finding('same', Severity::Warning),
        F::finding('same', Severity::Critical),
        F::finding('other', Severity::Info, IssueType::CacheMissRate, queryFingerprint: null),
    ]));

    expect($outcomes)->toHaveCount(2)
        ->and(app(IssueRepository::class)->findByFingerprint('same')->occurrences)->toBe(1)
        ->and(app(IssueRepository::class)->findByFingerprint('same')->severity)->toBe(Severity::Critical)
        ->and(DB::table('sherlock_issues')->count())->toBe(2);
});

it('stores a useful, AI-ready context', function (): void {
    $trace = F::traceData([F::query(timeMs: 900.0), F::query(timeMs: 12.0)]);

    $id = issueService()->recordFindings($trace->id, F::recorded($trace, [F::finding()], explainPlans: ['fp-users' => F::plan()]))[0]->issueId;
    $context = app(IssueRepository::class)->find($id)->context;

    expect($context['type'])->toBe('slow_query')
        ->and($context['sql'])->toBe('select * from users where id = ?')
        ->and($context['metrics'])->toEqual(['time_ms' => 912.4, 'count' => 1])
        ->and($context['location'])->toMatchArray(['file' => 'app/Http/Controllers/UserController.php', 'line' => 42])
        ->and($context['recommendation'])->toBe('Add an index on users.email')
        ->and($context['entry'])->toMatchArray(['type' => 'request', 'name' => 'GET /users', 'method' => 'GET', 'route' => 'users.index'])
        ->and($context['trace'])->toMatchArray(['id' => $trace->id, 'db_count' => 2, 'record_reason' => 'local'])
        ->and($context['verdict']['bottleneck'])->toBe('database')
        ->and($context['explain']['insights'][0]['suggested_index'])->toBe('users(email)')
        ->and($context['explain']['raw'])->toBe(['query_block' => ['select_id' => 1]])
        ->and($context['query'])->toMatchArray(['fingerprint' => 'fp-users', 'count' => 2, 'max_ms' => 900.0]);
});

it('includes exception details for error issues and slow calls for external issues', function (): void {
    $trace = F::traceData(type: EntryType::Job, name: 'App\\Jobs\\Charge', logs: [F::exception()], httpCalls: [F::httpCall()]);
    $exception = new Anabil\Sherlock\Data\Finding(IssueType::Exception, Severity::Critical, 'RuntimeException', 'Something failed', 'exc', location: new Anabil\Sherlock\Data\SourceLocation('app/Services/Billing.php', 10));
    $external = new Anabil\Sherlock\Data\Finding(IssueType::SlowExternalCall, Severity::Warning, 'Slow call', '', 'ext');

    issueService()->recordFindings($trace->id, F::recorded($trace, [$exception, $external]));

    $exc = app(IssueRepository::class)->findByFingerprint('exc')->context;
    $ext = app(IssueRepository::class)->findByFingerprint('ext')->context;

    expect($exc['exception'])->toMatchArray(['class' => 'RuntimeException', 'message' => 'Something failed', 'line' => 10])
        ->and($exc['exception']['frames'])->toHaveCount(1)
        ->and($exc['entry']['type'])->toBe('job')
        ->and($ext['http_calls'][0]['host'])->toBe('api.example.com');
});

it('dispatches the AI -> notification chain for new and regressed issues when enabled', function (): void {
    config()->set('sherlock.notifications.enabled', true);
    app()->forgetInstance(IssueService::class);
    Bus::fake();

    $id = issueService()->recordFindings('t1', F::recorded(findings: [F::finding()]))[0]->issueId;
    issueService()->recordFindings('t2', F::recorded(findings: [F::finding()]));

    Bus::assertChained([
        fn (AnalyzeIssueJob $job): bool => $job->issueId === $id && $job->fresh === false,
        NotifyIssueJob::class,
    ]);
    Bus::assertDispatchedTimes(AnalyzeIssueJob::class, 1);

    issueService()->changeStatus($id, IssueStatus::Resolved);
    issueService()->recordFindings('t3', F::recorded(findings: [F::finding()]));

    Bus::assertDispatched(AnalyzeIssueJob::class, fn (AnalyzeIssueJob $job): bool => $job->fresh === true);
});

it('does not dispatch jobs when AI and notifications are disabled', function (): void {
    config()->set('sherlock.notifications.enabled', false);
    config()->set('sherlock.ai.enabled', false);
    app()->forgetInstance(IssueService::class);
    Bus::fake();

    issueService()->recordFindings('t1', F::recorded(findings: [F::finding()]));

    Bus::assertNothingDispatched();
});

/** Simulate a PHP-FPM process (the test runner is a console process). */
function issueServiceInHttpWorker(bool $http): void
{
    $property = new ReflectionProperty(app(), 'isRunningInConsole');
    $property->setValue(app(), ! $http);
    app()->forgetInstance(IssueService::class);
}

it('skips the AI job on a sync queue inside an HTTP request (it would hold the PHP worker)', function (): void {
    config()->set('sherlock.ai.enabled', true);
    config()->set('sherlock.ai.min_severity', 'info');
    config()->set('sherlock.notifications.enabled', true);
    config()->set('sherlock.queue.connection', 'sync');
    issueServiceInHttpWorker(true);
    Bus::fake();

    try {
        issueService()->recordFindings('t1', F::recorded(findings: [F::finding()]));
    } finally {
        issueServiceInHttpWorker(false);
    }

    Bus::assertNotDispatched(AnalyzeIssueJob::class);
    Bus::assertDispatched(NotifyIssueJob::class, 1);
    // No analysis is scheduled: "not analyzed" (null), not "pending".
    expect(DB::table('sherlock_issues')->value('ai_status'))->toBeNull();
});

it('keeps the AI chain on a real queue, or on a sync queue outside HTTP requests', function (bool $http, string $connection): void {
    config()->set('sherlock.ai.enabled', true);
    config()->set('sherlock.ai.min_severity', 'info');
    config()->set('sherlock.notifications.enabled', true);
    config()->set('queue.connections.redis-like', ['driver' => 'database']);
    config()->set('sherlock.queue.connection', $connection);
    issueServiceInHttpWorker($http);
    Bus::fake();

    try {
        issueService()->recordFindings('t1', F::recorded(findings: [F::finding()]));
    } finally {
        issueServiceInHttpWorker(false);
    }

    Bus::assertChained([AnalyzeIssueJob::class, NotifyIssueJob::class]);
    expect(DB::table('sherlock_issues')->value('ai_status'))->toBe('pending');
})->with([
    'http request, real queue' => [true, 'redis-like'],
    'queue worker / command, sync queue' => [false, 'sync'],
]);

it('dispatches nothing on a sync queue inside an HTTP request when only AI is enabled', function (): void {
    config()->set('sherlock.ai.enabled', true);
    config()->set('sherlock.notifications.enabled', false);
    config()->set('sherlock.queue.connection', 'sync');
    issueServiceInHttpWorker(true);
    Bus::fake();

    try {
        issueService()->recordFindings('t1', F::recorded(findings: [F::finding()]));
    } finally {
        issueServiceInHttpWorker(false);
    }

    Bus::assertNothingDispatched();
});
