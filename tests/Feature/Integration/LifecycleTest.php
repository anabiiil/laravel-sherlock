<?php

declare(strict_types=1);

use Anabil\Tracing\Contracts\IssueService;
use Anabil\Tracing\Enums\IssueStatus;
use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Events\IssueDetected;
use Anabil\Tracing\Events\IssueRecurred;
use Anabil\Tracing\Events\IssueRegressed;
use Anabil\Tracing\Facades\Tracing;
use Anabil\Tracing\Jobs\AnalyzeIssueJob;
use Anabil\Tracing\Jobs\PersistTraceJob;
use Anabil\Tracing\Tests\Feature\Integration\Fixtures\IntegrationJob;
use Anabil\Tracing\Tests\Feature\Integration\Support\BootsTracing;
use Anabil\Tracing\Tests\Feature\Integration\Support\Integration;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

uses(BootsTracing::class);

beforeEach(function (): void {
    Integration::setUp('local');
    Http::fake(['api.example.test/*' => Http::response(['rate' => 1.1])]);
});

afterEach(function (): void {
    // The migration rollback runs on the tracing connection: give it back a working one.
    config()->set('tracing.storage.connection', null);
    Integration::tearDown();
    unset($_ENV['TRACING_MODE'], $_SERVER['TRACING_MODE']);
    putenv('TRACING_MODE');
});

/** Boot a fresh application with TRACING_MODE (read at register time). */
function lifecycleBootWithMode(string $mode): void
{
    $_ENV['TRACING_MODE'] = $_SERVER['TRACING_MODE'] = $mode;
    putenv('TRACING_MODE='.$mode);
    test()->refreshApplication();
}

it('persists through the queue (storage.persistence = queue) with the sync driver', function (): void {
    config()->set('tracing.storage.persistence', 'queue');
    config()->set('queue.default', 'sync');

    $this->get('/integration/report')->assertOk();

    expect(Integration::count('tracing_traces'))->toBe(1)
        ->and(Integration::count('tracing_queries'))->toBe(Integration::POSTS + 2)
        ->and(array_column(Integration::rows('tracing_issues'), 'type'))->toContain(IssueType::NPlusOne->value);
});

it('queues a PersistTraceJob after the response instead of writing inline', function (): void {
    config()->set('tracing.storage.persistence', 'queue');
    Queue::fake();

    $this->get('/integration/report')->assertOk();

    Queue::assertPushed(PersistTraceJob::class, 1);
    expect(Integration::count('tracing_traces'))->toBe(0);
});

it('reopens a resolved issue as regressed, fires IssueRegressed and resets the AI status', function (): void {
    $events = [];
    Event::listen([IssueDetected::class, IssueRecurred::class, IssueRegressed::class], function (object $event) use (&$events): void {
        $events[] = $event::class;
    });

    $this->get('/integration/n-plus-one')->assertOk();
    $issue = Integration::rows('tracing_issues')[0];

    app(IssueService::class)->changeStatus($issue['id'], IssueStatus::Resolved);
    DB::table('tracing_issues')->where('id', $issue['id'])->update(['ai_status' => 'completed']);
    expect(DB::table('tracing_issues')->where('id', $issue['id'])->value('status'))->toBe('resolved');

    $this->get('/integration/n-plus-one')->assertOk();
    $this->get('/integration/n-plus-one')->assertOk();

    $after = DB::table('tracing_issues')->where('id', $issue['id'])->first();
    expect(Integration::count('tracing_issues'))->toBe(1)
        ->and($after->status)->toBe('open')
        ->and($after->ai_status)->toBe('pending')
        ->and($after->regressed_at)->not->toBeNull()
        ->and($after->resolved_at)->toBeNull()
        ->and((int) $after->occurrences)->toBe(3)
        ->and($events)->toBe([IssueDetected::class, IssueRegressed::class, IssueRecurred::class]);
});

it('dispatches a fresh AI analysis chain for a regression when AI is enabled', function (): void {
    config()->set('tracing.ai.enabled', true);
    Bus::fake([AnalyzeIssueJob::class]);

    $this->get('/integration/n-plus-one')->assertOk();
    $id = Integration::rows('tracing_issues')[0]['id'];
    Bus::assertDispatched(AnalyzeIssueJob::class, static fn (AnalyzeIssueJob $job): bool => $job->issueId == $id && ! $job->fresh);

    app(IssueService::class)->changeStatus($id, IssueStatus::Resolved);
    $this->get('/integration/n-plus-one')->assertOk();

    Bus::assertDispatched(AnalyzeIssueJob::class, static fn (AnalyzeIssueJob $job): bool => $job->issueId == $id && $job->fresh);
    Bus::assertDispatchedTimes(AnalyzeIssueJob::class, 2);
});

it('never records its own persistence queries (tracing connection = app connection)', function (): void {
    expect(config('tracing.storage.connection'))->toBeNull();

    foreach (range(1, 3) as $ignored) {
        $this->get('/integration/report')->assertOk();
    }

    expect(Integration::count('tracing_traces'))->toBe(3);
    foreach (Integration::rows('tracing_queries') as $query) {
        expect($query['normalized_sql'])->not->toContain('tracing_');
    }
    foreach (Integration::rows('tracing_issues') as $issue) {
        expect((string) $issue['context'])->not->toContain('`tracing_');
    }
});

it('never records its own persistence queries on a dedicated tracing connection', function (): void {
    config()->set('database.connections.tracing', config('database.connections.mysql'));
    config()->set('tracing.storage.connection', 'tracing');

    $this->get('/integration/report')->assertOk();
    $this->get('/integration/report')->assertOk();

    expect(Integration::count('tracing_traces'))->toBe(2);
    foreach (Integration::rows('tracing_queries') as $query) {
        expect($query['connection'])->toBe('mysql')
            ->and($query['normalized_sql'])->not->toContain('tracing_');
    }
});

it('never traces its own persist job, even when the user replaced ignore.jobs', function (): void {
    config()->set('tracing.ignore.jobs', []);
    config()->set('tracing.storage.persistence', 'queue');
    config()->set('queue.default', 'sync');

    $this->get('/integration/report')->assertOk();
    $this->get('/integration/fast')->assertOk();

    expect(array_column(Integration::rows('tracing_traces'), 'type'))->toBe(['request', 'request']);
});

it('never traces its own persist job on a real worker, even when the user replaced ignore.jobs', function (): void {
    Schema::create('jobs', static function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    config()->set('tracing.ignore.jobs', []);
    config()->set('tracing.storage.persistence', 'queue');
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);

    $this->get('/integration/fast')->assertOk();
    expect(DB::table('jobs')->count())->toBe(1);

    $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true])->assertSuccessful();
    app()->terminate();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(array_column(Integration::rows('tracing_traces'), 'type'))->toBe(['request']);

    Schema::drop('jobs');
});

it('does not start a unit while capture is paused', function (): void {
    config()->set('queue.default', 'sync');

    Tracing::pause(static function (): void {
        dispatch(new IntegrationJob); // PendingDispatch is destructed (and runs) inside the pause
    });
    app()->terminate();

    expect(Integration::count('tracing_traces'))->toBe(0);
});

it('captures nothing and touches no tracing table when mode is off', function (): void {
    lifecycleBootWithMode('off');
    Integration::setUp('off');
    Http::fake(['api.example.test/*' => Http::response(['rate' => 1.1])]);

    $touched = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $event) use (&$touched): void {
        if (str_contains($event->sql, 'tracing_')) {
            $touched[] = $event->sql;
        }
    });

    $this->get('/integration/report')->assertOk();
    $this->get('/integration/boom')->assertStatus(500);
    $this->get('/integration/log-error')->assertOk();
    app()->terminate();

    expect($touched)->toBe([]);
    foreach (Integration::storageTables() as $table) {
        expect(Integration::count($table))->toBe(0, $table);
    }
});

it('never breaks the request when the tracing connection is misconfigured', function (): void {
    config()->set('database.connections.broken', array_merge(config('database.connections.mysql'), ['port' => 1, 'host' => '127.0.0.1']));
    config()->set('tracing.storage.connection', 'broken');

    $this->get('/integration/report')->assertOk()->assertJson(['authors' => Integration::POSTS]);
    $this->get('/integration/boom')->assertStatus(500);

    expect(Integration::count('tracing_traces'))->toBe(0);
});

it('never breaks the request when the tracing connection does not exist', function (): void {
    config()->set('tracing.storage.connection', 'does_not_exist');

    $this->get('/integration/report')->assertOk();
});

it('never breaks the request when the tracing tables are missing', function (): void {
    Schema::drop('tracing_traces');

    $this->get('/integration/report')->assertOk()->assertJson(['authors' => Integration::POSTS]);
    $this->get('/integration/log-error')->assertOk();

    // Restore it for the migration rollback.
    Schema::create('tracing_traces', static fn ($table) => $table->char('id', 26));
});

it('never breaks the request when the queue is broken in queued persistence', function (): void {
    config()->set('tracing.storage.persistence', 'queue');
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', ['driver' => 'database', 'table' => 'missing_jobs_table', 'queue' => 'default', 'retry_after' => 90]);

    $this->get('/integration/report')->assertOk();
});
