<?php

declare(strict_types=1);

use Anabil\Sherlock\Contracts\DigestService;
use Anabil\Sherlock\Contracts\NotificationDispatcher;
use Anabil\Sherlock\Digest\DefaultDigestService;
use Anabil\Sherlock\Digest\DigestBuilder;
use Anabil\Sherlock\Jobs\SendDigestJob;
use Anabil\Sherlock\Providers\DigestServiceProvider;
use Anabil\Sherlock\Tests\Feature\Digest\FakeNotificationDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

/** Re-resolve the schedule (and the digest service) so the current config applies. */
function digestScheduleEvents(): array
{
    app()->forgetInstance(DigestBuilder::class);
    app()->forgetInstance(DigestService::class);
    app()->forgetInstance(Schedule::class);

    return collect(app(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event): bool => $event->description === DigestServiceProvider::SCHEDULE_NAME)
        ->values()
        ->all();
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00'));
    Cache::flush();
    config()->set('sherlock.digest.enabled', true);
    config()->set('sherlock.digest.every_days', 1);
    config()->set('sherlock.digest.at', '09:00');
    app()->instance(NotificationDispatcher::class, new FakeNotificationDispatcher);
});

it('does not schedule the digest while disabled', function (): void {
    config()->set('sherlock.digest.enabled', false);

    expect(digestScheduleEvents())->toBe([]);
});

it('schedules a daily check at digest.at when enabled', function (): void {
    config()->set('sherlock.digest.at', '07:30');

    $events = digestScheduleEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('30 7 * * *');
});

it('runs the scheduled check only when the digest is due', function (): void {
    [$event] = digestScheduleEvents();

    expect($event->filtersPass(app()))->toBeTrue();

    app(DigestService::class)->send();

    expect($event->filtersPass(app()))->toBeFalse();

    $this->travelTo(CarbonImmutable::parse('2026-10-21 09:00:00'));
    expect($event->filtersPass(app()))->toBeTrue();
});

it('queues SendDigestJob from the scheduler when due', function (): void {
    Bus::fake([SendDigestJob::class]);
    digestScheduleEvents();

    $this->artisan('schedule:run')->assertSuccessful();

    Bus::assertDispatched(SendDigestJob::class, fn (SendDigestJob $job): bool => $job->force === false);
});

it('does not queue the job when the digest is not due', function (): void {
    Bus::fake([SendDigestJob::class]);
    digestScheduleEvents();
    app(DigestService::class)->send(); // just sent

    $this->artisan('schedule:run')->assertSuccessful();

    Bus::assertNotDispatched(SendDigestJob::class);
});

it('sends the digest from the job and honours the queue config', function (): void {
    config()->set('sherlock.queue.connection', 'sync');
    config()->set('sherlock.queue.name', 'sherlock');
    $dispatcher = new FakeNotificationDispatcher;
    app()->instance(NotificationDispatcher::class, $dispatcher);
    digestScheduleEvents();

    $job = new SendDigestJob;
    expect($job->connection)->toBe('sync')
        ->and($job->queue)->toBe('sherlock')
        ->and($job->tries)->toBe(1);

    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']); // second run: no longer due

    expect($dispatcher->sent)->toHaveCount(1)
        ->and(Cache::has(DefaultDigestService::LAST_SENT_KEY))->toBeTrue();
});

it('sends from sherlock:digest only when enabled and due, unless forced', function (): void {
    $dispatcher = new FakeNotificationDispatcher;
    app()->instance(NotificationDispatcher::class, $dispatcher);

    config()->set('sherlock.digest.enabled', false);
    digestScheduleEvents();
    $this->artisan('sherlock:digest')
        ->expectsOutputToContain('The digest is disabled')
        ->assertSuccessful();
    expect($dispatcher->sent)->toBe([]);

    $this->artisan('sherlock:digest', ['--force' => true])
        ->expectsOutputToContain('Digest sent to: mail')
        ->assertSuccessful();
    expect($dispatcher->sent)->toHaveCount(1);

    config()->set('sherlock.digest.enabled', true);
    digestScheduleEvents();
    $this->artisan('sherlock:digest')
        ->expectsOutputToContain('not due yet')
        ->assertSuccessful();
    expect($dispatcher->sent)->toHaveCount(1);

    $this->travelTo(CarbonImmutable::parse('2026-10-21 09:00:00'));
    $this->artisan('sherlock:digest')
        ->expectsOutputToContain('Digest sent')
        ->assertSuccessful();
    expect($dispatcher->sent)->toHaveCount(2);
});
