<?php

declare(strict_types=1);

use Anabil\Tracing\Contracts\IssueRepository;
use Anabil\Tracing\Contracts\TraceRepository;
use Anabil\Tracing\Tests\Feature\Storage\StorageFixtures as F;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

/** Seeds 2 old + 1 fresh traces and 2 old + 1 fresh issues relative to "now" (2026-10-20 12:00). */
function seedRetentionData(): void
{
    $now = CarbonImmutable::parse('2026-10-20 12:00:00');
    $traces = app(TraceRepository::class);
    $issues = app(IssueRepository::class);

    $traces->store(F::recorded(F::traceData([F::query()], startedAt: $now->subDays(30))));
    $traces->store(F::recorded(F::traceData([F::query()], startedAt: $now->subDays(10))));
    $traces->store(F::recorded(F::traceData([F::query()], startedAt: $now->subDay())));

    $issues->recordOccurrence(F::finding('i-200'), [], $now->subDays(200));
    $issues->recordOccurrence(F::finding('i-100'), [], $now->subDays(100));
    $issues->recordOccurrence(F::finding('i-1'), [], $now->subDay());
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00'));
});

it('prunes using the configured retention', function (): void {
    seedRetentionData();
    config()->set('tracing.retention.traces', 7);
    config()->set('tracing.retention.issues', 90);
    config()->set('tracing.retention.chunk_size', 1);

    $this->artisan('tracing:prune')
        ->expectsOutputToContain('Traces: deleted 2')
        ->expectsOutputToContain('Issues: deleted 2')
        ->assertSuccessful();

    expect(DB::table('tracing_traces')->count())->toBe(1)
        ->and(DB::table('tracing_queries')->count())->toBe(1)
        ->and(DB::table('tracing_issues')->pluck('fingerprint')->all())->toBe(['i-1']);
});

it('keeps data forever when retention is null', function (): void {
    seedRetentionData();
    config()->set('tracing.retention.traces', null);
    config()->set('tracing.retention.issues', null);

    $this->artisan('tracing:prune')
        ->expectsOutputToContain('kept forever')
        ->assertSuccessful();

    expect(DB::table('tracing_traces')->count())->toBe(3)
        ->and(DB::table('tracing_issues')->count())->toBe(3);
});

it('prunes relative to the current time', function (): void {
    seedRetentionData();
    config()->set('tracing.retention.traces', 7);
    config()->set('tracing.retention.issues', null);

    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00'));
    $this->artisan('tracing:prune')->assertSuccessful();

    expect(DB::table('tracing_traces')->count())->toBe(0)
        ->and(DB::table('tracing_issues')->count())->toBe(3);
});

it('asks for confirmation before clearing', function (): void {
    seedRetentionData();

    $this->artisan('tracing:clear')
        ->expectsConfirmation('Delete all stored traces and issues?', 'no')
        ->assertSuccessful();

    expect(DB::table('tracing_traces')->count())->toBe(3);

    $this->artisan('tracing:clear')
        ->expectsConfirmation('Delete all stored traces and issues?', 'yes')
        ->assertSuccessful();

    expect(DB::table('tracing_traces')->count())->toBe(0)
        ->and(DB::table('tracing_queries')->count())->toBe(0)
        ->and(DB::table('tracing_issues')->count())->toBe(0)
        ->and(DB::table('tracing_issue_traces')->count())->toBe(0);
});

it('clears only traces or only issues, optionally older than N days, with --force', function (): void {
    seedRetentionData();

    $this->artisan('tracing:clear', ['--only' => 'traces', '--older-than' => 20, '--force' => true])
        ->expectsOutputToContain('Traces: deleted 1')
        ->assertSuccessful();

    expect(DB::table('tracing_traces')->count())->toBe(2)
        ->and(DB::table('tracing_issues')->count())->toBe(3);

    $this->artisan('tracing:clear', ['--only' => 'issues', '--force' => true])
        ->expectsOutputToContain('Issues: deleted 3')
        ->assertSuccessful();

    expect(DB::table('tracing_traces')->count())->toBe(2)
        ->and(DB::table('tracing_issues')->count())->toBe(0);
});

it('rejects invalid clear options', function (): void {
    $this->artisan('tracing:clear', ['--only' => 'everything', '--force' => true])->assertFailed();
    $this->artisan('tracing:clear', ['--older-than' => 'soon', '--force' => true])->assertFailed();
});

it('schedules tracing:prune daily', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'tracing:prune'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 3 * * *');
});
