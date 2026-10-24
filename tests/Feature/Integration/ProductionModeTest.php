<?php

declare(strict_types=1);

use Anabil\Sherlock\Analysis\Explain\ExplainService;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Tests\Feature\Integration\Support\BootsSherlock;
use Anabil\Sherlock\Tests\Feature\Integration\Support\Integration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

uses(BootsSherlock::class);

beforeEach(function (): void {
    Integration::setUp('production');
    Http::fake(['api.example.test/*' => Http::response(['rate' => 1.1])]);
});

afterEach(function (): void {
    Integration::tearDown();
    Schema::dropIfExists('integration_events');
    Schema::dropIfExists('jobs');
});

it('persists nothing for a fast clean request when not sampled', function (): void {
    $this->get('/integration/fast')->assertOk();

    foreach (Integration::storageTables() as $table) {
        expect(Integration::count($table))->toBe(0, $table);
    }
});

it('persists an n+1 request with reason threshold, without bindings', function (): void {
    $this->get('/integration/n-plus-one')->assertOk();

    $traces = Integration::rows('sherlock_traces');
    expect($traces)->toHaveCount(1)
        ->and($traces[0]['record_reason'])->toBe('threshold')
        ->and(Integration::json($traces[0]['details'])['full'])->toBeFalse();

    $queries = Integration::rows('sherlock_queries');
    expect($queries)->toHaveCount(Integration::POSTS + 1)
        ->and(array_filter(array_column($queries, 'bindings'), static fn (mixed $b): bool => $b !== null))->toBe([]);

    $issue = Integration::rows('sherlock_issues');
    expect($issue)->toHaveCount(1)
        ->and($issue[0]['type'])->toBe(IssueType::NPlusOne->value);

    // Production captures the location of the first occurrence too (one backtrace per SQL string).
    $context = Integration::json($issue[0]['context']);
    expect($context['location']['file'])->toBe(Integration::relative(Integration::controllerFile()))
        ->and($context['location']['line'])->toBe(Integration::lineOf(Integration::controllerFile(), 'n-plus-one-only'));
});

it('persists a slow-query request with reason threshold', function (): void {
    $this->get('/integration/report')->assertOk();

    $traces = Integration::rows('sherlock_traces');
    expect($traces)->toHaveCount(1)
        ->and($traces[0]['record_reason'])->toBe('threshold');

    $types = array_column(Integration::rows('sherlock_issues'), 'type');
    expect($types)->toContain(IssueType::SlowQuery->value, IssueType::NPlusOne->value);
});

it('persists every unit as a sample when sample_rate is 1', function (): void {
    config()->set('sherlock.sample_rate', 1.0);

    $this->get('/integration/fast')->assertOk();

    $traces = Integration::rows('sherlock_traces');
    expect($traces)->toHaveCount(1)
        ->and($traces[0]['record_reason'])->toBe('sample')
        ->and(Integration::count('sherlock_issues'))->toBe(0)
        ->and(Integration::rows('sherlock_queries')[0]['bindings'])->toBeNull();
});

it('reports threshold rather than sample when a sampled trace also crosses a threshold', function (): void {
    config()->set('sherlock.sample_rate', 1.0);

    $this->get('/integration/n-plus-one')->assertOk();

    expect(Integration::rows('sherlock_traces')[0]['record_reason'])->toBe('threshold');
});

it('fully traces a request carrying the trigger header, with redacted bindings', function (): void {
    $this->get('/integration/fast', ['X-Sherlock' => 'integration-header-secret'])->assertOk();

    $traces = Integration::rows('sherlock_traces');
    expect($traces)->toHaveCount(1)
        ->and($traces[0]['record_reason'])->toBe('header')
        ->and(Integration::json($traces[0]['details'])['full'])->toBeTrue();

    // count(*) has no bindings; the stored value is the (empty) redacted list, not NULL.
    expect(Integration::rows('sherlock_queries')[0]['bindings'])->not->toBeNull();

    $this->get('/integration/n-plus-one', ['X-Sherlock' => 'integration-header-secret'])->assertOk();
    $authorBindings = array_values(array_filter(
        Integration::rows('sherlock_queries'),
        static fn (array $q): bool => str_contains((string) $q['normalized_sql'], 'from `integration_authors` where'),
    ));
    expect(Integration::json($authorBindings[0]['bindings']))->toBe([1]);
});

it('ignores a wrong trigger header', function (): void {
    $this->get('/integration/fast', ['X-Sherlock' => 'wrong-secret'])->assertOk();

    expect(Integration::count('sherlock_traces'))->toBe(0);
});

it('explains a slow parameterized query in production: missing index found, no literal stored or queued', function (): void {
    $literal = 'evt-literal-7Q9Z';
    $amount = 987654;

    Schema::dropIfExists('integration_events');
    Schema::create('integration_events', static function (Blueprint $table): void {
        $table->id();
        $table->string('payload_ref', 64);
        $table->unsignedInteger('amount');
    });
    $rows = [];
    for ($i = 1; $i <= 2000; $i++) {
        $rows[] = ['payload_ref' => 'ref-'.$i, 'amount' => $i];
    }
    foreach (array_chunk($rows, 1000) as $chunk) {
        DB::table('integration_events')->insert($chunk);
    }
    DB::select('analyze table integration_events');

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
    config()->set('sherlock.storage.persistence', 'queue');
    config()->set('sherlock.queue.connection', 'database');
    config()->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);

    // Every SELECT is "slow enough" to be explained; the query itself stays fast (no slow-query issue).
    config()->set('sherlock.explain.enabled', true);
    config()->set('sherlock.explain.min_query_ms', 0);
    cache()->flush();
    Integration::rebuildQueryCollector();

    Route::get('/integration/events', static function () use ($literal, $amount) {
        return response()->json([
            'event' => DB::table('integration_events')->where('payload_ref', $literal)->where('amount', '>', $amount)->first(),
        ]);
    });

    $this->get('/integration/events')->assertOk();

    // The queued payload (what sits in Redis / SQS / the jobs table) carries no literal value.
    $payloads = DB::table('jobs')->pluck('payload')->all();
    expect($payloads)->toHaveCount(1);
    foreach ($payloads as $payload) {
        expect((string) $payload)->not->toContain($literal)->not->toContain((string) $amount);
    }

    $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true])->assertSuccessful();
    app()->terminate();

    $issues = Integration::rows('sherlock_issues');
    $missing = array_values(array_filter($issues, static fn (array $issue): bool => $issue['type'] === IssueType::MissingIndex->value));
    expect($missing)->toHaveCount(1);

    // Located (the query is EXPLAIN-eligible), in a closure of this file: relative path only.
    expect($missing[0]['title'])->toContain('in tests/Feature/Integration/ProductionModeTest.php:')
        ->toEndWith('(closure)')
        ->not->toContain(Integration::packageRoot());

    $context = Integration::json($missing[0]['context']);
    expect($context['location']['file'])->toBe('tests/Feature/Integration/ProductionModeTest.php')
        ->and((string) json_encode($context, JSON_UNESCAPED_SLASHES))->not->toContain(Integration::packageRoot());

    expect($context['metrics']['suggested_index'])->toBe('integration_events(payload_ref, amount)')
        ->and($context['recommendation'])->toContain('$table->index([\'payload_ref\', \'amount\'])')
        // The raw plan is kept, with its literals scrubbed.
        ->and(json_encode($context['explain']['raw']))->toContain('attached_condition');

    $queries = Integration::rows('sherlock_queries');
    expect($queries)->toHaveCount(1)
        ->and($queries[0]['bindings'])->toBeNull();

    // No literal value in any sherlock_* column ...
    foreach (Integration::storageTables() as $table) {
        foreach (DB::table($table)->get() as $row) {
            foreach ((array) $row as $column => $value) {
                expect((string) $value)->not->toContain($literal, "{$table}.{$column}")
                    ->not->toContain((string) $amount, "{$table}.{$column}");
            }
        }
    }

    // ... nor in the EXPLAIN cache.
    $cached = cache()->get(ExplainService::CACHE_PREFIX.$context['query_fingerprint']);
    expect($cached)->toBeArray()
        ->and(serialize($cached))->not->toContain($literal)->not->toContain((string) $amount);
});
