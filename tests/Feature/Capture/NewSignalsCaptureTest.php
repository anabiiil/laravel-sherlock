<?php

declare(strict_types=1);

use Anabil\Sherlock\Collectors\JobCollector;
use Anabil\Sherlock\Collectors\LazyLoadingCollector;
use Anabil\Sherlock\Context\ContextManager;
use Anabil\Sherlock\Context\UnitLifecycle;
use Anabil\Sherlock\Context\WorkerMemoryMonitor;
use Anabil\Sherlock\Data\EntryCandidate;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Facades\Sherlock;
use Anabil\Sherlock\Providers\CaptureServiceProvider;
use Anabil\Sherlock\Tests\Feature\Integration\Fixtures\IntegrationJob;
use Anabil\Sherlock\Tests\Feature\Integration\Fixtures\Post;
use Anabil\Sherlock\Tests\Feature\Integration\Support\BootsSherlock;
use Anabil\Sherlock\Tests\Feature\Integration\Support\Integration;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

uses(BootsSherlock::class);

/** Memory "after each job" for the worker test: +2MB per job. */
function leakingMemoryMonitor(): WorkerMemoryMonitor
{
    $memory = 50 * 1048576;

    return new WorkerMemoryMonitor(
        growthBytes: 4 * 1048576,
        minJobs: 2,
        bufferSize: 10,
        warmupJobs: 1,
        reader: static function () use (&$memory): int {
            return $memory += 2 * 1048576;
        },
    );
}

function createJobsTable(): void
{
    Schema::dropIfExists('jobs');
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
}

afterEach(function (): void {
    Integration::tearDown();
    Schema::dropIfExists('jobs');
    LazyLoadingCollector::uninstall();
    Model::handleLazyLoadingViolationUsing(null);
    Model::preventLazyLoading(false);
});

it('stores the release, CPU time and load average on every persisted trace', function (): void {
    Integration::setUp('local');
    config()->set('sherlock.release', 'abc1234');
    app()->forgetInstance(ContextManager::class);
    app()->forgetInstance(UnitLifecycle::class);

    $lifecycle = app(UnitLifecycle::class);
    $lifecycle->begin(new EntryCandidate(EntryType::Command, 'integration:signals'));
    Post::query()->count();
    $lifecycle->end();

    $trace = Integration::rows('sherlock_traces')[0];
    $release = Integration::rows('sherlock_releases');

    expect($trace['release'])->toBe('abc1234')
        ->and($trace['cpu_ms'])->toBeNumeric()
        ->and($trace['load_avg'])->toBeNumeric()
        ->and($release)->toHaveCount(1)
        ->and($release[0]['release'])->toBe('abc1234');
});

it('measures the queue wait of a job from the database worker record', function (): void {
    Integration::setUp('production');
    createJobsTable();

    dispatch(new IntegrationJob)->onQueue('emails');
    // The job became available 45s ago.
    DB::table('jobs')->update(['available_at' => time() - 45, 'created_at' => time() - 45]);

    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'emails', '--once' => true, '--stop-when-empty' => true])->assertSuccessful();
    app()->terminate();

    $trace = Integration::rows('sherlock_traces')[0];
    $issues = collect(Integration::rows('sherlock_issues'))->keyBy('type');

    expect((float) $trace['queue_wait_ms'])->toBeGreaterThanOrEqual(44000.0)->toBeLessThan(48000.0)
        ->and($trace['bottleneck'])->toBe('queue_backlog')
        ->and($issues)->toHaveKey('queue_wait')
        ->and($issues['queue_wait']['title'])->toContain('in queue `emails`')
        ->and((float) $issues['queue_wait']['impact_ms'])->toBeGreaterThanOrEqual(44000.0);
});

it('reads createdAt from a real Laravel 12+ payload', function (): void {
    Integration::setUp('production');
    createJobsTable();

    dispatch(new IntegrationJob);
    $payload = json_decode((string) DB::table('jobs')->value('payload'), true);

    expect($payload)->toHaveKey('createdAt')
        ->and($payload['createdAt'])->toBeInt()
        ->and($payload)->toHaveKey('delay');
})->skip(fn (): bool => version_compare(app()->version(), '12.0.0', '<'), 'Laravel 11 payloads carry no push time.');

it('detects a memory leak across the jobs of a real database worker', function (): void {
    Integration::setUp('production');
    createJobsTable();

    // Rewire the job collector with a fake memory source (collectors are wired at boot).
    foreach ([JobProcessing::class, JobProcessed::class, JobExceptionOccurred::class, JobFailed::class] as $event) {
        Event::forget($event);
    }
    app()->instance(WorkerMemoryMonitor::class, leakingMemoryMonitor());
    app()->forgetInstance(JobCollector::class);
    app(JobCollector::class)->register();

    foreach (range(1, 6) as $i) {
        dispatch(new IntegrationJob);
    }

    $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true])->assertSuccessful();
    app()->terminate();

    $leaks = collect(Integration::rows('sherlock_issues'))->where('type', 'memory_leak')->values();

    expect($leaks)->toHaveCount(1)
        ->and($leaks[0]['title'])->toContain('Memory leak in queue worker `default`')
        ->and($leaks[0]['occurrences'])->toBeGreaterThanOrEqual(2)
        ->and(Integration::json($leaks[0]['context'])['memory_leak']['contributors'])->toHaveKey(IntegrationJob::class);

    $withLeak = collect(Integration::rows('sherlock_traces'))
        ->filter(fn (array $row): bool => (Integration::json($row['details'])['memory_leak'] ?? null) !== null);

    expect($withLeak)->not->toBeEmpty();
});

it('names the lazy-loaded relation of an N+1 and still loads it (local mode)', function (): void {
    Integration::setUp('local');
    // Collectors are wired when the app boots (here before the mode was switched to local).
    expect(app(LazyLoadingCollector::class)->install())->toBeTrue()
        ->and(Model::preventsLazyLoading())->toBeTrue();

    $this->get('/integration/n-plus-one')->assertOk()->assertJson(['authors' => Integration::POSTS]);

    $issue = collect(Integration::rows('sherlock_issues'))->firstWhere('type', 'n_plus_one');
    $context = Integration::json($issue['context']);

    expect($issue['title'])->toContain('lazy loading Post::author')
        ->and($context['metrics']['relation'])->toBe('author')
        ->and($context['metrics']['model'])->toBe(Post::class)
        ->and($context['metrics']['relation_source'])->toBe('lazy_loading')
        ->and($context['lazy_loads'][0])->toMatchArray([
            'model' => Post::class, 'relation' => 'author', 'count' => Integration::POSTS, 'table' => 'integration_authors',
        ])
        ->and($context['recommendation'])->toContain("Post::with('author')");
});

it('never overrides a lazy-loading handler the app set itself', function (): void {
    Integration::setUp('local');
    LazyLoadingCollector::uninstall();

    $seen = [];
    Model::preventLazyLoading();
    Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) use (&$seen): void {
        $seen[] = $relation;
        Sherlock::recordLazyLoad($model, $relation);
    });

    $collector = app(LazyLoadingCollector::class);

    expect($collector->install())->toBeFalse()
        ->and($collector->isInstalled())->toBeFalse();

    $lifecycle = app(UnitLifecycle::class);
    $lifecycle->begin(new EntryCandidate(EntryType::Command, 'integration:own-handler'));
    $names = Post::query()->orderBy('id')->get()->map(fn (Post $post): string => $post->author->name)->all();
    $lifecycle->end();

    $trace = Integration::rows('sherlock_traces')[0];

    expect($names)->toHaveCount(Integration::POSTS)
        ->and($seen)->toHaveCount(Integration::POSTS)
        ->and(Integration::json($trace['details'])['lazy_loads'][0]['relation'])->toBe('author');
});

it('enables lazy-loading detection in local mode only, unless configured', function (): void {
    $provider = new CaptureServiceProvider(app());
    $detects = function (string $mode, mixed $detect) use ($provider): bool {
        config()->set('sherlock.mode', $mode);
        config()->set('sherlock.lazy_loading.detect', $detect);

        return $provider->detectsLazyLoading() && in_array(LazyLoadingCollector::class, $provider->collectors(), true);
    };

    expect($detects('local', null))->toBeTrue()
        ->and($detects('production', null))->toBeFalse()
        ->and($detects('production', 'true'))->toBeTrue()
        ->and($detects('local', false))->toBeFalse()
        // Booted in production mode: prevention was never enabled.
        ->and(Model::preventsLazyLoading())->toBeFalse();
});

it('records one lazy load per relation and model per trace, in O(1)', function (): void {
    Integration::setUp('local');
    $lifecycle = app(UnitLifecycle::class);

    $lifecycle->begin(new EntryCandidate(EntryType::Command, 'integration:dedup'));
    $context = app(ContextManager::class)->active();
    foreach (range(1, 50) as $i) {
        Sherlock::recordLazyLoad(Post::class, 'author');
    }
    $trace = $context?->toTraceData();
    $lifecycle->end();

    expect($trace?->lazyLoads)->toHaveCount(1)
        ->and($trace?->lazyLoads[0]->count)->toBe(50)
        ->and($trace?->lazyLoads[0]->table)->toBe('integration_authors');
});
