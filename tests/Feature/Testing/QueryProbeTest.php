<?php

declare(strict_types=1);

use Anabil\Sherlock\Testing\InteractsWithSherlock;
use Anabil\Sherlock\Testing\ProbeThresholds;
use Anabil\Sherlock\Testing\QueryProbe;
use Anabil\Sherlock\Tests\Feature\Testing\Fixtures\ProbeAuthor;
use Anabil\Sherlock\Tests\Feature\Testing\Support\ProbeFixtures;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(InteractsWithSherlock::class, ProbeFixtures::class);

beforeEach(function (): void {
    config()->set('sherlock.explain.enabled', false);
    self::createProbeSchema();
});

afterEach(function (): void {
    self::dropProbeSchema();
    unset($_ENV['SHERLOCK_MODE'], $_SERVER['SHERLOCK_MODE']);
    putenv('SHERLOCK_MODE');
});

/** Raw QueryExecuted listeners of the application's dispatcher. */
function probeQueryListeners(): array
{
    return app('events')->getRawListeners()[QueryExecuted::class] ?? [];
}

it('works with sherlock.mode=off, when the global capture registers nothing', function (): void {
    $_ENV['SHERLOCK_MODE'] = $_SERVER['SHERLOCK_MODE'] = 'off';
    putenv('SHERLOCK_MODE=off');
    $this->refreshApplication();
    config()->set('sherlock.explain.enabled', false);
    self::createProbeSchema();

    expect(config('sherlock.mode'))->toBe('off');

    $probe = $this->sherlockProbe(fn () => ProbeAuthor::query()->get()->each(fn (ProbeAuthor $a): int => $a->probePosts->count()));

    expect($probe->count())->toBe(1 + self::AUTHORS)
        ->and($probe->nPlusOnes())->toHaveCount(1);

    expect(self::failureOf(fn () => $this->assertNoNPlusOne(fn () => $this->get('/probe/lazy'))))
        ->toContain('Location: '.self::locationOf(self::controllerFile(), 'controller-n-plus-one'));
});

it('removes its listener after the callback, keeping the other listeners in order', function (): void {
    $before = probeQueryListeners();

    $this->sherlockProbe(function () use ($before): void {
        expect(probeQueryListeners())->toHaveCount(count($before) + 1);
        DB::select('select 1');
    });

    expect(probeQueryListeners())->toBe($before);

    // Queries after the callback are not recorded, nor seen by the next probe.
    DB::select('select 2');
    $next = $this->sherlockProbe(fn () => DB::select('select 3'));
    expect($next->count())->toBe(1)
        ->and($next->queries()[0]->sql)->toBe('select 3');
});

it('rethrows exceptions from the callback and still removes its listener', function (): void {
    $before = probeQueryListeners();

    expect(fn () => $this->assertNoNPlusOne(function (): void {
        DB::select('select 1');

        throw new RuntimeException('callback exploded');
    }))->toThrow(RuntimeException::class, 'callback exploded');

    expect(probeQueryListeners())->toBe($before);
});

it('supports nested probes', function (): void {
    $outer = $this->sherlockProbe(function (): int {
        DB::select('select 1');
        $inner = $this->sherlockProbe(fn () => DB::select('select 2'));

        return $inner->count();
    });

    expect($outer->value)->toBe(1)
        ->and($outer->count())->toBe(2);
});

it('does not count Sherlock\'s own queries (trace persistence after a request in local mode)', function (): void {
    config()->set('sherlock.mode', 'local');
    config()->set('sherlock.sample_rate', 0.0);

    $this->assertQueryCount(2, fn () => $this->get('/probe/eager')->assertOk());

    expect(DB::table('sherlock_traces')->count())->toBe(1);
});

it('still records queries when events are faked', function (): void {
    Event::fake();

    expect($this->sherlockProbe(fn () => ProbeAuthor::query()->get())->count())->toBe(1);
});

it('honours sherlock.ignore.queries', function (): void {
    config()->set('sherlock.ignore.queries', ['select 1*']);

    $probe = $this->sherlockProbe(function (): void {
        DB::select('select 1');
        DB::select('select 2');
    });

    expect($probe->count())->toBe(1);
});

it('reads thresholds from sherlock.testing.*, then sherlock.thresholds.*', function (): void {
    expect(ProbeThresholds::fromConfig(config()))->toEqual(new ProbeThresholds(2, 2, 100.0, 50));

    config()->set('sherlock.thresholds.n_plus_one.warning_count', 9); // production only: tests keep 2
    config()->set('sherlock.thresholds.duplicate_query.min_count', 3);
    config()->set('sherlock.thresholds.slow_query.warning_ms', 250);
    config()->set('sherlock.thresholds.excessive_queries.warning_count', 80);
    expect(ProbeThresholds::fromConfig(config()))->toEqual(new ProbeThresholds(2, 3, 250.0, 80));

    config()->set('sherlock.testing', ['n_plus_one' => 3, 'duplicate_query' => 4, 'slow_query_ms' => 25, 'max_queries' => 20]);
    expect(ProbeThresholds::fromConfig(config()))->toEqual(new ProbeThresholds(3, 4, 25.0, 20));
});

it('can be used directly with custom thresholds', function (): void {
    $probe = QueryProbe::make()
        ->withThresholds(new ProbeThresholds(nPlusOne: 4))
        ->run(fn () => ProbeAuthor::query()->get()->each(fn (ProbeAuthor $a): int => $a->probePosts->count()));

    expect($probe->nPlusOnes())->toBe([])
        ->and($probe->nPlusOnes(3))->toHaveCount(1)
        ->and($probe->excessiveQueries(4))->toHaveCount(1)
        ->and($probe->excessiveQueries())->toBe([]);
});
