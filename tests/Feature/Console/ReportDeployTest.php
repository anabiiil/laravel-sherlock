<?php

declare(strict_types=1);

use Anabil\Sherlock\Tests\Feature\Dashboard\ReleaseFixtures as R;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00'));
});

it('prints the top issues by impact and the latest deploy', function (): void {
    R::seed();

    // Expectations are ordered and each one consumes one written line / table.
    $this->artisan('sherlock:report', ['--days' => 30])
        ->expectsOutputToContain('Top issues by impact')
        ->expectsOutputToContain('| N+1 on posts since v2  | 4.20 s |')
        ->expectsOutputToContain('Latest deploy: '.R::CURRENT.' vs '.R::PREVIOUS)
        ->expectsOutputToContain('1 route slower, 1 new issue, 1 regressed issue since deploy '.R::CURRENT)
        ->expectsOutputToContain('| GET /orders    | slower | 100 ms | 300 ms | +200%  | +50 ms | 0.0     | all (biased) |')
        ->expectsOutputToContain('Raise sample_rate for fair comparisons')
        ->expectsOutputToContain('| regressed |')
        ->doesntExpectOutputToContain('unchanged') // unchanged entries are left out
        ->assertSuccessful();
});

it('says when there are fewer than two releases', function (): void {
    $this->artisan('sherlock:report')
        ->expectsOutputToContain('Top issues by impact')
        ->expectsOutputToContain('Fewer than two releases recorded. Set SHERLOCK_RELEASE (or deploy from git) and run "php artisan config:cache" on deploy.')
        ->assertSuccessful();
});

it('compares two given releases with --compare and narrows by --type', function (): void {
    R::seed();

    $this->artisan('sherlock:report', ['--compare' => R::PREVIOUS.','.R::CURRENT])
        ->expectsOutputToContain('Deploy: '.R::PREVIOUS.' vs '.R::CURRENT)
        ->expectsOutputToContain('since deploy '.R::PREVIOUS)
        ->expectsOutputToContain('-66.7%')
        ->assertSuccessful();

    $this->artisan('sherlock:report', ['--compare' => R::CURRENT.','.R::PREVIOUS, '--type' => 'job'])
        ->expectsOutputToContain('(jobs)')
        ->expectsOutputToContain('No entry got slower, faster, new or gone.')
        ->assertSuccessful();
});

it('compares --release with the release deployed before it', function (): void {
    R::seed();

    $this->artisan('sherlock:report', ['--release' => R::CURRENT])
        ->expectsOutputToContain('Deploy: '.R::CURRENT.' vs '.R::PREVIOUS)
        ->assertSuccessful();

    $this->artisan('sherlock:report', ['--release' => R::PREVIOUS])
        ->expectsOutputToContain('No release was recorded before '.R::PREVIOUS)
        ->assertSuccessful();
});

it('rejects invalid release options', function (): void {
    R::seed();

    $this->artisan('sherlock:report', ['--release' => 'nope'])->expectsOutputToContain('Unknown release [nope]')->assertFailed();
    $this->artisan('sherlock:report', ['--compare' => R::CURRENT])->assertFailed();
    $this->artisan('sherlock:report', ['--compare' => R::CURRENT.','.R::CURRENT])->assertFailed();
    $this->artisan('sherlock:report', ['--compare' => R::CURRENT.',missing'])->expectsOutputToContain('Unknown release [missing]')->assertFailed();
    $this->artisan('sherlock:report', ['--release' => R::CURRENT, '--compare' => R::CURRENT.','.R::PREVIOUS])->assertFailed();
});
