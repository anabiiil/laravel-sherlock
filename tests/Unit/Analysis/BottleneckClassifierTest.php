<?php

declare(strict_types=1);

use Anabil\Tracing\Analysis\DefaultBottleneckClassifier;
use Anabil\Tracing\Data\HttpCallRecord;
use Anabil\Tracing\Data\Verdict;
use Anabil\Tracing\Enums\Bottleneck;
use Anabil\Tracing\Tests\Unit\Analysis\AnalysisFactory as F;

beforeEach(fn () => $this->classifier = new DefaultBottleneckClassifier);

it('classifies a database bound trace with a human reason', function (): void {
    $queries = [F::query('select * from `orders` where `user_id` = 1', 900)];
    for ($i = 0; $i < 37; $i++) {
        $queries[] = F::query('select * from `items` where `id` = '.$i, 21.08);
    }

    $verdict = $this->classifier->classify(F::enrich(F::trace($queries, 2400)));

    expect($verdict->bottleneck)->toBe(Bottleneck::Database)
        ->and($verdict->reason)->toBe('70% of 2.40s spent in 38 queries (top query 900ms on `orders`).')
        ->and($verdict->totalMs)->toBe(2400.0)
        ->and($verdict->percentage(Verdict::SEGMENT_DB))->toBe(70.0)
        ->and(array_keys($verdict->breakdownMs))->toBe(Verdict::SEGMENTS)
        ->and(array_sum($verdict->breakdownMs))->toEqualWithDelta(2400.0, 0.1);
});

it('applies the ratio rules at their edges', function (array $trace, Bottleneck $expected): void {
    expect($this->classifier->classify(F::trace(...$trace))->bottleneck)->toBe($expected);
})->with([
    'db exactly 50%' => [['queries' => [F::query('select 1', 500)], 'durationMs' => 1000], Bottleneck::Database],
    'db 49% is not database' => [['queries' => [F::query('select 1', 490)], 'durationMs' => 1000], Bottleneck::Mixed],
    'external 40%' => [['httpCalls' => [new HttpCallRecord('GET', 'https://api.x.com', 200, 400)], 'durationMs' => 1000], Bottleneck::ExternalService],
    'cache 30%' => [['cacheMs' => 300, 'durationMs' => 1000], Bottleneck::Cache],
    'bootstrap 40%' => [['bootstrapMs' => 400, 'durationMs' => 1000], Bottleneck::Bootstrap],
    'application 60%' => [['queries' => [F::query('select 1', 100)], 'durationMs' => 1000, 'bootstrapMs' => 300], Bottleneck::Application],
    'nothing measured is application' => [['durationMs' => 800], Bottleneck::Application],
]);

it('describes external, cache, bootstrap, application and mixed verdicts', function (): void {
    $external = $this->classifier->classify(F::trace(durationMs: 3100, httpCalls: [
        new HttpCallRecord('GET', 'https://api.stripe.com/v1/x', 200, 1200),
        new HttpCallRecord('GET', 'https://api.stripe.com/v1/y', 200, 500),
    ]));
    expect($external->reason)->toBe('55% of 3.10s spent in 2 external HTTP calls (slowest 1.20s to api.stripe.com).');

    $cache = $this->classifier->classify(F::trace(durationMs: 800, cacheMs: 300, cacheHits: 20, cacheMisses: 80));
    expect($cache->reason)->toBe('38% of 800ms spent in cache operations (100 lookups, 80% misses).');

    expect($this->classifier->classify(F::trace(durationMs: 400, bootstrapMs: 200))->reason)->toStartWith('50% of 400ms spent booting the framework')
        ->and($this->classifier->classify(F::trace([F::query('select 1', 50)], 1000))->reason)->toBe('95% of 1.00s spent in application code (database 5% in 1 queries, external HTTP 0%).');

    $mixed = $this->classifier->classify(F::trace([F::query('select 1', 450)], 1000, httpCalls: [new HttpCallRecord('GET', 'https://a.io', 200, 300)]));
    expect($mixed->bottleneck)->toBe(Bottleneck::Mixed)
        ->and($mixed->reason)->toBe('No single dominant segment in 1.00s: database 45%, external HTTP 30%, application 25%.');
});

it('returns unknown for (near) zero or too short durations', function (): void {
    expect($this->classifier->classify(F::trace(durationMs: 0.0))->bottleneck)->toBe(Bottleneck::Unknown)
        ->and($this->classifier->classify(F::trace(durationMs: 49.9))->reason)->toBe('Too short to classify (50ms).')
        ->and((new DefaultBottleneckClassifier(['min_duration_ms' => 0]))->classify(F::trace(durationMs: 0.0))->bottleneck)->toBe(Bottleneck::Unknown);
});

it('never produces a negative application segment when segments overlap', function (): void {
    // concurrent HTTP pool: measured time exceeds the wall-clock duration
    $verdict = $this->classifier->classify(F::trace(durationMs: 1000, httpCalls: [
        new HttpCallRecord('GET', 'https://a.io', 200, 900),
        new HttpCallRecord('GET', 'https://b.io', 200, 900),
    ]));

    expect($verdict->ms(Verdict::SEGMENT_APPLICATION))->toBe(0.0)
        ->and($verdict->percentage(Verdict::SEGMENT_EXTERNAL_HTTP))->toBe(100.0)
        ->and($verdict->bottleneck)->toBe(Bottleneck::ExternalService);
});

it('reads ratio rules from config', function (): void {
    $classifier = new DefaultBottleneckClassifier(['database' => 0.3]);

    expect($classifier->classify(F::trace([F::query('select 1', 300)], 1000))->bottleneck)->toBe(Bottleneck::Database);
});
