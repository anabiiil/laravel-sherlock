<?php

declare(strict_types=1);

use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Tests\Feature\Integration\Fixtures\Author;
use Anabil\Sherlock\Tests\Feature\Integration\Fixtures\IntegrationJob;
use Anabil\Sherlock\Tests\Feature\Integration\Support\BootsSherlock;
use Anabil\Sherlock\Tests\Feature\Integration\Support\Integration;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

uses(BootsSherlock::class);

beforeEach(function (): void {
    Integration::setUp('production');

    // The console kernel only forwards CommandStarting / CommandFinished outside unit tests
    // (php artisan always does); turn the real forwarding on.
    app(ConsoleKernel::class)->rerouteSymfonyCommandEvents();

    Artisan::command('integration:crunch', function (): int {
        foreach (range(1, 3) as $id) {
            Author::query()->whereKey($id)->first();
        }

        return 0;
    });
});

afterEach(function (): void {
    Integration::tearDown();
    Schema::dropIfExists('jobs');
});

it('persists a sync queue job trace with entry type job and the job class as name', function (): void {
    config()->set('queue.default', 'sync');

    dispatch(new IntegrationJob);

    $traces = Integration::rows('sherlock_traces');
    expect($traces)->toHaveCount(1)
        ->and($traces[0]['type'])->toBe('job')
        ->and($traces[0]['name'])->toBe(IntegrationJob::class)
        ->and($traces[0]['record_reason'])->toBe('threshold')
        ->and((int) $traces[0]['db_count'])->toBe(Integration::POSTS + 1)
        ->and(Integration::json($traces[0]['details'])['metadata']['connection'])->toBe('sync');

    $issue = Integration::rows('sherlock_issues')[0];
    $context = Integration::json($issue['context']);
    expect($issue['type'])->toBe(IssueType::NPlusOne->value)
        ->and($context['entry']['type'])->toBe('job')
        ->and($context['location']['file'])->toBe('tests/Feature/Integration/Fixtures/IntegrationJob.php');
});

it('persists a job processed by a database queue worker', function (): void {
    Schema::create('jobs', static function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);

    dispatch(new IntegrationJob);
    expect(Integration::count('sherlock_traces'))->toBe(0);

    // queue:work itself is ignored; the job it processes is its own unit.
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true])->assertSuccessful();
    app()->terminate();

    $traces = Integration::rows('sherlock_traces');
    expect($traces)->toHaveCount(1)
        ->and($traces[0]['type'])->toBe('job')
        ->and($traces[0]['name'])->toBe(IntegrationJob::class)
        ->and(Integration::json($traces[0]['details'])['metadata']['connection'])->toBe('database');
});

it('creates no trace for a faked queue', function (): void {
    Queue::fake();

    dispatch(new IntegrationJob);
    app()->terminate();

    Queue::assertPushed(IntegrationJob::class);
    expect(Integration::count('sherlock_traces'))->toBe(0);
});

it('folds a job dispatched inside a request into the request trace', function (): void {
    config()->set('queue.default', 'sync');

    $this->get('/integration/nested')->assertOk();

    $traces = Integration::rows('sherlock_traces');
    expect($traces)->toHaveCount(1)
        ->and($traces[0]['type'])->toBe('request')
        ->and($traces[0]['name'])->toBe('GET /integration/nested')
        ->and((int) $traces[0]['db_count'])->toBe(Integration::POSTS + 1);
});

it('persists an artisan command trace with entry type command, name and exit code', function (): void {
    config()->set('sherlock.sample_rate', 1.0);

    $this->artisan('integration:crunch')->assertSuccessful();
    app()->terminate();

    $traces = Integration::rows('sherlock_traces');
    expect($traces)->toHaveCount(1)
        ->and($traces[0]['type'])->toBe('command')
        ->and($traces[0]['name'])->toBe('integration:crunch')
        ->and((int) $traces[0]['status_code'])->toBe(0)
        ->and($traces[0]['record_reason'])->toBe('sample')
        ->and((int) $traces[0]['db_count'])->toBe(3);
});
