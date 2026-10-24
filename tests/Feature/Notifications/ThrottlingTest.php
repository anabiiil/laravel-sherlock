<?php

declare(strict_types=1);

use Anabil\Sherlock\Contracts\IssueService;
use Anabil\Sherlock\Data\IssueOutcome;
use Anabil\Sherlock\Enums\IssueStatus;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\OccurrenceType;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Events\IssueRecurred;
use Anabil\Sherlock\Notifications\Jobs\SendThrottledSummaryJob;
use Anabil\Sherlock\Notifications\Throttler;
use Anabil\Sherlock\Tests\Feature\Notifications\NotificationFixtures as N;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

function notifThrottler(): Throttler
{
    return app(Throttler::class);
}

/** Record one more occurrence in the database and return its (recurring) outcome. */
function notifRepeat(string $fingerprint = 'notif-issue', IssueType $type = IssueType::SlowQuery): IssueOutcome
{
    $outcome = N::record($fingerprint, type: $type);
    expect($outcome->occurrence)->toBe(OccurrenceType::Recurring);

    return $outcome;
}

/** Create the issue and simulate the immediate first notification sent by the chain. */
function notifFirstNotified(string $fingerprint = 'notif-issue', IssueType $type = IssueType::SlowQuery): IssueOutcome
{
    $first = N::record($fingerprint, type: $type);
    N::issues()->markNotified($first->issueId, CarbonImmutable::now());

    return $first;
}

/** @param  'sync'|'redis'  $driver */
function notifQueue(string $driver): void
{
    config()->set('sherlock.queue.connection', null);
    config()->set('queue.default', $driver);
    config()->set("queue.connections.{$driver}", ['driver' => $driver]);
}

beforeEach(function (): void {
    N::configure(['channels' => ['slack']]);
    N::fakeHttp();
    Mail::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 10:00:00'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('counts repeats silently within the window and summarizes once after it (sync queue)', function (): void {
    notifQueue('sync');
    $first = notifFirstNotified();

    foreach ([5, 20, 50] as $minute) {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00')->addMinutes($minute));
        event(new IssueRecurred(notifRepeat()));
    }

    expect(N::find($first->issueId)->pendingNotifyCount)->toBe(3);
    Http::assertNothingSent();

    // After the window: the next repeat triggers exactly one summary covering all four.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 11:01:00'));
    event(new IssueRecurred(notifRepeat()));

    $issue = N::find($first->issueId);

    expect(Http::recorded())->toHaveCount(1)
        ->and($issue->pendingNotifyCount)->toBe(0)
        ->and($issue->lastNotifiedAt?->format('H:i'))->toBe('11:01');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'Still happening: Slow query on orders')
        && str_contains($request->body(), 'Occurred 4 more times in the last hour (5 in total).'));

    // The next window starts from scratch.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 11:10:00'));
    event(new IssueRecurred(notifRepeat()));

    expect(Http::recorded())->toHaveCount(1)
        ->and(N::find($first->issueId)->pendingNotifyCount)->toBe(1);
});

it('schedules a single delayed summary per window on an async queue', function (): void {
    notifQueue('redis');
    Bus::fake([SendThrottledSummaryJob::class]);
    $first = notifFirstNotified();

    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:50:00'));
    expect(notifThrottler()->recordRepeat(notifRepeat()))->toBeTrue()
        ->and(notifThrottler()->recordRepeat(notifRepeat()))->toBeFalse()
        ->and(notifThrottler()->recordRepeat(notifRepeat()))->toBeFalse();

    Bus::assertDispatchedTimes(SendThrottledSummaryJob::class, 1);
    Bus::assertDispatched(SendThrottledSummaryJob::class, fn (SendThrottledSummaryJob $job): bool => $job->issueId === $first->issueId
        && $job->delay === 600);
    expect(N::find($first->issueId)->pendingNotifyCount)->toBe(3);

    // Not due yet: a direct run sends nothing and releases the window guard.
    (new SendThrottledSummaryJob($first->issueId))->handle(notifThrottler());
    Http::assertNothingSent();

    $this->travelTo(CarbonImmutable::parse('2026-10-20 11:00:30'));
    (new SendThrottledSummaryJob($first->issueId))->handle(notifThrottler());

    expect(Http::recorded())->toHaveCount(1)
        ->and(N::find($first->issueId)->pendingNotifyCount)->toBe(0);
    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'Occurred 3 more times in the last hour'));
});

it('caps delays for drivers with a maximum delay', function (): void {
    notifQueue('redis');
    Bus::fake([SendThrottledSummaryJob::class]);
    notifFirstNotified();

    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:01:00'));
    notifThrottler()->recordRepeat(notifRepeat());

    Bus::assertDispatched(SendThrottledSummaryJob::class, fn (SendThrottledSummaryJob $job): bool => $job->delay === Throttler::MAX_DELAY_SECONDS);
});

it('guards against concurrent workers dispatching or sending twice', function (): void {
    notifQueue('sync');
    Bus::fake([SendThrottledSummaryJob::class]);
    $first = notifFirstNotified();

    $this->travelTo(CarbonImmutable::parse('2026-10-20 11:05:00'));
    $a = notifRepeat();
    $b = notifRepeat();

    // Two workers handle repeats at the same time, each with its own Throttler instance.
    expect(notifThrottler()->recordRepeat($a))->toBeTrue()
        ->and(notifThrottler()->recordRepeat($b))->toBeFalse();
    Bus::assertDispatchedTimes(SendThrottledSummaryJob::class, 1);

    // The job delivered twice (at-least-once queues): the second run finds nothing pending.
    expect(notifThrottler()->flush($first->issueId))->toBeTrue()
        ->and(notifThrottler()->flush($first->issueId))->toBeFalse()
        ->and(Http::recorded())->toHaveCount(1);
});

it('throttles repeats of exceptions the same way', function (): void {
    notifQueue('sync');
    $first = notifFirstNotified('notif-error', IssueType::Exception);

    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:30:00'));
    event(new IssueRecurred(notifRepeat('notif-error', IssueType::Exception)));
    Http::assertNothingSent();

    $this->travelTo(CarbonImmutable::parse('2026-10-20 11:30:00'));
    event(new IssueRecurred(notifRepeat('notif-error', IssueType::Exception)));

    expect(Http::recorded())->toHaveCount(1)
        ->and(N::find($first->issueId)->pendingNotifyCount)->toBe(0);
});

it('uses the first sighting as the window start when nothing was sent yet', function (): void {
    notifQueue('sync');
    $first = N::record();

    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:30:00'));
    notifThrottler()->recordRepeat(notifRepeat());
    $this->travelTo(CarbonImmutable::parse('2026-10-20 11:00:00'));
    notifThrottler()->recordRepeat(notifRepeat());

    expect(Http::recorded())->toHaveCount(1)
        ->and(N::find($first->issueId)->lastNotifiedAt)->not->toBeNull();
});

it('does not count repeats that can never be notified', function (array $config, Severity $severity): void {
    notifQueue('sync');
    N::configure($config);
    $first = N::record('quiet', $severity);

    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00'));
    notifThrottler()->recordRepeat(N::record('quiet', $severity));

    expect(N::find($first->issueId)->pendingNotifyCount)->toBe(0);
    Http::assertNothingSent();
})->with([
    'disabled' => [['enabled' => false], Severity::Critical],
    'below threshold' => [['min_severity' => 'critical'], Severity::Warning],
    'type toggled off' => [['notify_on' => ['new' => false]], Severity::Critical],
]);

it('ignores ignored issues and skips resolved ones in the job', function (): void {
    notifQueue('sync');
    $first = notifFirstNotified();
    N::issues()->updateStatus($first->issueId, IssueStatus::Ignored);
    $ignored = N::record();

    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00'));
    expect($ignored->isIgnored())->toBeTrue()
        ->and(notifThrottler()->recordRepeat($ignored))->toBeFalse()
        ->and(N::find($first->issueId)->pendingNotifyCount)->toBe(0);

    N::issues()->updateStatus($first->issueId, IssueStatus::Open);
    N::issues()->incrementPendingNotifications($first->issueId);
    N::issues()->updateStatus($first->issueId, IssueStatus::Resolved);

    expect(notifThrottler()->flush($first->issueId))->toBeFalse()
        ->and(notifThrottler()->flush(999999))->toBeFalse();
    Http::assertNothingSent();
});

it('never lets a throttling failure escape the listener', function (): void {
    app()->bind(Throttler::class, fn () => throw new RuntimeException('boom'));

    event(new IssueRecurred(new IssueOutcome(1, 'fp', IssueType::SlowQuery, Severity::Critical, OccurrenceType::Recurring, 2)));

    expect(true)->toBeTrue();
});

it('is wired to recurring occurrences recorded by the issue service', function (): void {
    notifQueue('sync');
    Bus::fake();
    app()->forgetInstance(IssueService::class);

    $service = app(IssueService::class);
    $finding = N::finding();
    $recorded = new Anabil\Sherlock\Data\RecordedTrace(
        Anabil\Sherlock\Tests\Feature\Storage\StorageFixtures::traceData(),
        new Anabil\Sherlock\Data\AnalysisResult([$finding], Anabil\Sherlock\Data\Verdict::unknown()),
        Anabil\Sherlock\Enums\RecordReason::Local,
        false,
    );

    [$new] = $service->recordFindings('trace-1', $recorded);
    N::issues()->markNotified($new->issueId, CarbonImmutable::now());
    $service->recordFindings('trace-2', $recorded);
    $service->recordFindings('trace-3', $recorded);

    expect(N::find($new->issueId)->pendingNotifyCount)->toBe(2);
});
