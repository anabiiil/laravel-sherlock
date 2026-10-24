<?php

declare(strict_types=1);

use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Tests\Feature\Integration\Support\BootsTracing;
use Anabil\Tracing\Tests\Feature\Integration\Support\Integration;
use Illuminate\Support\Facades\Http;

uses(BootsTracing::class);

beforeEach(function (): void {
    Integration::setUp('production');
    Http::fake(['api.example.test/*' => Http::response(['rate' => 1.1])]);
});

afterEach(fn () => Integration::tearDown());

it('persists nothing for a fast clean request when not sampled', function (): void {
    $this->get('/integration/fast')->assertOk();

    foreach (Integration::storageTables() as $table) {
        expect(Integration::count($table))->toBe(0, $table);
    }
});

it('persists an n+1 request with reason threshold, without bindings', function (): void {
    $this->get('/integration/n-plus-one')->assertOk();

    $traces = Integration::rows('tracing_traces');
    expect($traces)->toHaveCount(1)
        ->and($traces[0]['record_reason'])->toBe('threshold')
        ->and(Integration::json($traces[0]['details'])['full'])->toBeFalse();

    $queries = Integration::rows('tracing_queries');
    expect($queries)->toHaveCount(Integration::POSTS + 1)
        ->and(array_filter(array_column($queries, 'bindings'), static fn (mixed $b): bool => $b !== null))->toBe([]);

    $issue = Integration::rows('tracing_issues');
    expect($issue)->toHaveCount(1)
        ->and($issue[0]['type'])->toBe(IssueType::NPlusOne->value);

    // Production captures the location of the first occurrence too (one backtrace per SQL string).
    $context = Integration::json($issue[0]['context']);
    expect($context['location']['file'])->toBe(Integration::relative(Integration::controllerFile()))
        ->and($context['location']['line'])->toBe(Integration::lineOf(Integration::controllerFile(), 'n-plus-one-only'));
});

it('persists a slow-query request with reason threshold', function (): void {
    $this->get('/integration/report')->assertOk();

    $traces = Integration::rows('tracing_traces');
    expect($traces)->toHaveCount(1)
        ->and($traces[0]['record_reason'])->toBe('threshold');

    $types = array_column(Integration::rows('tracing_issues'), 'type');
    expect($types)->toContain(IssueType::SlowQuery->value, IssueType::NPlusOne->value);
});

it('persists every unit as a sample when sample_rate is 1', function (): void {
    config()->set('tracing.sample_rate', 1.0);

    $this->get('/integration/fast')->assertOk();

    $traces = Integration::rows('tracing_traces');
    expect($traces)->toHaveCount(1)
        ->and($traces[0]['record_reason'])->toBe('sample')
        ->and(Integration::count('tracing_issues'))->toBe(0)
        ->and(Integration::rows('tracing_queries')[0]['bindings'])->toBeNull();
});

it('reports threshold rather than sample when a sampled trace also crosses a threshold', function (): void {
    config()->set('tracing.sample_rate', 1.0);

    $this->get('/integration/n-plus-one')->assertOk();

    expect(Integration::rows('tracing_traces')[0]['record_reason'])->toBe('threshold');
});

it('fully traces a request carrying the trigger header, with redacted bindings', function (): void {
    $this->get('/integration/fast', ['X-Tracing' => 'integration-header-secret'])->assertOk();

    $traces = Integration::rows('tracing_traces');
    expect($traces)->toHaveCount(1)
        ->and($traces[0]['record_reason'])->toBe('header')
        ->and(Integration::json($traces[0]['details'])['full'])->toBeTrue();

    // count(*) has no bindings; the stored value is the (empty) redacted list, not NULL.
    expect(Integration::rows('tracing_queries')[0]['bindings'])->not->toBeNull();

    $this->get('/integration/n-plus-one', ['X-Tracing' => 'integration-header-secret'])->assertOk();
    $authorBindings = array_values(array_filter(
        Integration::rows('tracing_queries'),
        static fn (array $q): bool => str_contains((string) $q['normalized_sql'], 'from `integration_authors` where'),
    ));
    expect(Integration::json($authorBindings[0]['bindings']))->toBe([1]);
});

it('ignores a wrong trigger header', function (): void {
    $this->get('/integration/fast', ['X-Tracing' => 'wrong-secret'])->assertOk();

    expect(Integration::count('tracing_traces'))->toBe(0);
});
