<?php

declare(strict_types=1);

use Anabil\Sherlock\Ai\CacheAiBudget;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

function aiBudgetFor(int $limit, ?DateTimeImmutable &$now = null, string $timezone = 'UTC', ?Repository $cache = null): CacheAiBudget
{
    $now ??= new DateTimeImmutable('2026-10-24 10:00:00', new DateTimeZone('UTC'));

    return new CacheAiBudget($cache ?? new Repository(new ArrayStore), $limit, $timezone, function () use (&$now): DateTimeImmutable {
        return $now;
    });
}

it('allows exactly daily_limit calls per day', function (): void {
    $budget = aiBudgetFor(2);

    expect($budget->consume())->toBeTrue()
        ->and($budget->consume())->toBeTrue()
        ->and($budget->consume())->toBeFalse()
        ->and($budget->consume())->toBeFalse()
        ->and($budget->used())->toBe(2)
        ->and($budget->remaining())->toBe(0)
        ->and($budget->limit())->toBe(2);
});

it('treats 0 as no calls at all (fail-safe)', function (): void {
    $budget = aiBudgetFor(0);

    expect($budget->consume())->toBeFalse()
        ->and($budget->used())->toBe(0)
        ->and($budget->remaining())->toBe(0);
});

it('treats a negative limit as unlimited', function (): void {
    $budget = aiBudgetFor(-1);

    foreach (range(1, 100) as $_) {
        expect($budget->consume())->toBeTrue();
    }

    expect($budget->limit())->toBe(-1)
        ->and($budget->used())->toBe(100)
        ->and($budget->remaining())->toBe(PHP_INT_MAX);
});

it('resets on a new day in the app timezone', function (): void {
    $cache = new Repository(new ArrayStore);
    $now = new DateTimeImmutable('2026-10-24 20:00:00', new DateTimeZone('UTC'));
    $budget = aiBudgetFor(1, $now, 'Asia/Riyadh', $cache);

    expect($budget->key())->toBe('sherlock:ai:budget:2026-10-24')
        ->and($budget->consume())->toBeTrue()
        ->and($budget->consume())->toBeFalse();

    // 21:30 UTC is already the next day in Riyadh (UTC+3).
    $now = new DateTimeImmutable('2026-10-24 21:30:00', new DateTimeZone('UTC'));

    expect($budget->key())->toBe('sherlock:ai:budget:2026-10-25')
        ->and($budget->used())->toBe(0)
        ->and($budget->consume())->toBeTrue();
});

it('shares the counter between instances using the same store', function (): void {
    $cache = new Repository(new ArrayStore);
    $a = aiBudgetFor(1, cache: $cache);
    $b = aiBudgetFor(1, cache: $cache);

    expect($a->consume())->toBeTrue()
        ->and($b->consume())->toBeFalse();
});
