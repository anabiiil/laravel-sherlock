<?php

declare(strict_types=1);

use Anabil\Sherlock\Contracts\AiProvider;
use Anabil\Sherlock\Contracts\DigestService;
use Anabil\Sherlock\Digest\DigestBuilder;
use Anabil\Sherlock\Tests\Feature\Dashboard\ReleaseFixtures as R;
use Anabil\Sherlock\Tests\Feature\Digest\SpyAiProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

function deployDigestBuilder(): DigestBuilder
{
    app()->forgetInstance(DigestBuilder::class);
    app()->forgetInstance(DigestService::class);

    return app(DigestBuilder::class);
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00'));
    Cache::flush();
    config()->set('sherlock.digest.limit', 10);
});

it('adds "Top by impact" and "Since last deploy" sections from stored data', function (): void {
    R::seed();
    $spy = new SpyAiProvider;
    app()->instance(AiProvider::class, $spy);
    $builder = deployDigestBuilder();
    $now = CarbonImmutable::now();

    $report = $builder->build($now->subDays(30), $now);
    $message = $builder->message($report);

    expect(array_map(fn ($i) => $i->fingerprint, $report->impactIssues))->toBe(['rel-new', 'rel-back', 'rel-gone'])
        ->and($report->deploy?->current)->toBe(R::CURRENT)
        ->and($report->deploy?->previous)->toBe(R::PREVIOUS)
        ->and($message->fields['Top by impact'])->toContain('• 4.20 s total · [WARNING] N+1 on posts since v2 (N+1 queries · 1x)')
        ->and($message->fields['Since last deploy'])->toStartWith('1 route slower, 1 new issue, 1 regressed issue since deploy '.R::CURRENT.' (vs '.R::PREVIOUS.')')
        ->and($message->fields['Since last deploy'])->toContain('• slower: GET /orders (request) 100 ms -> 300 ms (+200%) [biased: all stored traces]')
        ->and($message->fields['Since last deploy'])->toContain('• new issue: [WARNING] N+1 on posts since v2 (N+1 queries)')
        ->and($message->fields['Since last deploy'])->toContain('• regressed: [WARNING] Came back after deploy (N+1 queries)')
        ->and($spy->calls)->toBe(0);
});

it('leaves "Since last deploy" out with fewer than two releases', function (): void {
    R::trace(R::CURRENT, 'GET /orders', 100.0);
    R::trace(R::CURRENT, 'GET /orders', 120.0);
    $builder = deployDigestBuilder();
    $now = CarbonImmutable::now();

    $report = $builder->build($now->subDay(), $now);

    expect($report->deploy)->toBeNull()
        ->and($builder->message($report)->fields)->not->toHaveKey('Since last deploy')
        ->and($builder->message($report)->fields)->not->toHaveKey('Top by impact');
});

it('leaves issues without measured impact out of "Top by impact"', function (): void {
    app(Anabil\Sherlock\Contracts\IssueRepository::class)->recordOccurrence(
        R::finding('no-impact', 'Costless exception', 0.0),
        [],
        CarbonImmutable::now()->subHour(),
    );
    $builder = deployDigestBuilder();
    $now = CarbonImmutable::now();

    expect($builder->build($now->subDay(), $now)->impactIssues)->toBe([]);
});
