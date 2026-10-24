<?php

declare(strict_types=1);

use Anabil\Tracing\Contracts\IssueRepository;
use Anabil\Tracing\Contracts\TraceRepository;
use Anabil\Tracing\Enums\Bottleneck;
use Anabil\Tracing\Enums\EntryType;
use Anabil\Tracing\Enums\IssueStatus;
use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Enums\Severity;
use Anabil\Tracing\Tests\Feature\Storage\StorageFixtures as F;
use Carbon\CarbonImmutable;

function seedReportData(): void
{
    $now = CarbonImmutable::now();
    $traces = app(TraceRepository::class);
    $issues = app(IssueRepository::class);

    $traces->store(F::recorded(F::traceData([F::query('select * from users where email = ?', 300.0, null, 'fp-users')], name: 'GET /users', durationMs: 450.0, startedAt: $now->subDay())));
    $traces->store(F::recorded(
        F::traceData([F::query('select * from orders where status = ?', 900.0, null, 'fp-orders')], EntryType::Job, 'App\\Jobs\\SyncOrders', 2400.0, $now->subDays(2)),
        bottleneck: Bottleneck::Application,
    ));
    $traces->store(F::recorded(F::traceData([F::query('select * from legacy_reports', 100.0, null, 'fp-legacy')], name: 'GET /legacy', durationMs: 9000.0, startedAt: $now->subDays(20))));

    $issues->recordOccurrence(F::finding('r-open', Severity::Critical, IssueType::NPlusOne, 'N+1 queries on orders'), [], $now->subDay());
    $issues->recordOccurrence(F::finding('r-open', Severity::Critical, IssueType::NPlusOne, 'N+1 queries on orders'), [], $now->subHours(5));
    $issues->recordOccurrence(F::finding('r-resolved', Severity::Warning, IssueType::SlowQuery, 'Resolved slow query'), [], $now->subDay());
    $issues->updateStatus($issues->findByFingerprint('r-resolved')->id, IssueStatus::Resolved, $now);
    $issues->recordOccurrence(F::finding('r-stale', Severity::Info, IssueType::CacheMissRate, 'Stale cache misses', null), [], $now->subDays(30));
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00'));
});

it('prints the issues, slowest entries, top queries and bottlenecks of the period', function (): void {
    seedReportData();

    $this->artisan('tracing:report')
        ->expectsOutputToContain('Tracing report')
        ->expectsOutputToContain('2026-10-13 12:00 to 2026-10-20 12:00')
        ->expectsOutputToContain('Top open issues')
        ->expectsOutputToContain('N+1 queries on orders')
        ->doesntExpectOutputToContain('Resolved slow query')
        ->doesntExpectOutputToContain('Stale cache misses')
        ->expectsOutputToContain('Slowest entries')
        ->expectsOutputToContain('App\\Jobs\\SyncOrders')
        ->expectsOutputToContain('GET /users')
        ->doesntExpectOutputToContain('GET /legacy')
        ->expectsOutputToContain('Top queries')
        ->expectsOutputToContain('select * from orders where status = ?')
        ->doesntExpectOutputToContain('legacy_reports')
        ->expectsOutputToContain('Bottleneck distribution')
        ->expectsOutputToContain('Application / Server')
        ->expectsOutputToContain('50%')
        ->assertSuccessful();
});

it('filters traces and slowest entries by --type and widens the period with --days', function (): void {
    seedReportData();

    $this->artisan('tracing:report', ['--type' => 'job'])
        ->expectsOutputToContain('Traces (job)')
        ->expectsOutputToContain('2.40 s')
        ->doesntExpectOutputToContain('GET /users')
        ->assertSuccessful();

    $this->artisan('tracing:report', ['--days' => 30])
        ->expectsOutputToContain('GET /legacy')
        ->expectsOutputToContain('legacy_reports')
        ->assertSuccessful();
});

it('handles an empty database', function (): void {
    $this->artisan('tracing:report')
        ->expectsOutputToContain('No data for this period.')
        ->assertSuccessful();
});

it('rejects invalid options', function (): void {
    $this->artisan('tracing:report', ['--type' => 'cron'])->assertFailed();
    $this->artisan('tracing:report', ['--days' => 0])->assertFailed();
    $this->artisan('tracing:report', ['--limit' => 'many'])->assertFailed();
});
