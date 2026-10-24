<?php

declare(strict_types=1);

use Anabil\Sherlock\Tests\Feature\Dashboard\DashboardFixtures as D;
use Anabil\Sherlock\Tests\Feature\Dashboard\ReleaseFixtures as R;

beforeEach(fn () => D::allowAccess());

it('shows the deploys nav item and an empty state without releases', function (): void {
    $this->get('/sherlock/deploys')
        ->assertOk()
        ->assertSee('Deploys')
        ->assertSee('No releases recorded yet.')
        ->assertSee('SHERLOCK_RELEASE')
        ->assertSee('php artisan config:cache')
        ->assertSee('git HEAD');

    $this->get('/sherlock')->assertOk()->assertSee(route('sherlock.deploys.index'), false);
});

it('explains that one release is not enough to compare', function (): void {
    R::trace(R::CURRENT, 'GET /orders', 100.0);

    $this->get('/sherlock/deploys')
        ->assertOk()
        ->assertSee('Only one release recorded so far')
        ->assertSee(R::CURRENT)
        ->assertDontSee('Compared with (before)');
});

it('compares the latest release with the previous one by default', function (): void {
    R::seed();

    $this->get('/sherlock/deploys')
        ->assertOk()
        ->assertSee('1 route slower, 1 new issue, 1 regressed issue since deploy '.R::CURRENT)
        ->assertSeeInOrder(['GET /orders', 'GET /brand-new', 'GET /users', 'App\\Jobs\\SyncStock', 'GET /removed'])
        ->assertSee('Slower')->assertSee('Faster')->assertSee('New')->assertSee('Gone')->assertSee('Unchanged')
        // 100ms -> 300ms: +200%, +200 ms; db 25 -> 75 ms.
        ->assertSee('100 ms')->assertSee('300 ms')
        ->assertSee('+200%')->assertSee('+200 ms')->assertSee('+50 ms')
        // 400 -> 200: -50%.
        ->assertSee('-50%')
        // Only 3 sampled traces per side (< compare_min_samples): every stored trace, flagged.
        ->assertSee('biased')
        ->assertSee('raise sample_rate for fair comparisons', false)
        ->assertSee('N+1 on posts since v2')
        ->assertSee('Came back after deploy')
        ->assertSee('Old slow export')
        ->assertSee('New in '.R::CURRENT)
        ->assertSee('Gone since '.R::PREVIOUS);
});

it('links new, regressed and gone issues to their issue pages', function (): void {
    R::seed();

    $response = $this->get('/sherlock/deploys')->assertOk();

    foreach (['rel-new', 'rel-back', 'rel-gone'] as $fingerprint) {
        $response->assertSee(route('sherlock.issues.show', ['issue' => D::issue($fingerprint)->id]), false);
    }
});

it('compares any two releases picked in the selectors and narrows by type', function (): void {
    R::seed();
    R::trace('v3-old', 'GET /orders', 100.0, Carbon\CarbonImmutable::now()->subDays(30));

    // Reversed pair: v1 "after" v2.
    $this->get('/sherlock/deploys?current='.R::PREVIOUS.'&previous='.R::CURRENT)
        ->assertOk()
        ->assertSee('since deploy '.R::PREVIOUS)
        ->assertSee('-67%');

    $this->get('/sherlock/deploys?type=job')
        ->assertOk()
        ->assertSee('App\\Jobs\\SyncStock')
        ->assertDontSee('GET /orders')
        ->assertSee('Jobs only');

    $this->get('/sherlock/deploys?current='.R::CURRENT.'&previous='.R::CURRENT)
        ->assertOk()
        ->assertSee('Pick two different releases to compare.');

    // The oldest release has nothing before it.
    $this->get('/sherlock/deploys?current=v3-old')
        ->assertOk()
        ->assertSee('No release recorded before v3-old.');
});

it('ignores unknown or malformed releases in the query string', function (): void {
    R::seed();

    $this->get('/sherlock/deploys?current=nope&previous=%3Cscript%3E')
        ->assertOk()
        ->assertSee('since deploy '.R::CURRENT)
        ->assertDontSee('<script>', false);
});

it('lists releases with first / last seen and links to their traces and issues', function (): void {
    R::seed();

    $this->get('/sherlock/deploys')
        ->assertOk()
        ->assertSee('Releases')
        ->assertSeeInOrder([R::CURRENT, R::PREVIOUS])
        ->assertSee(route('sherlock.traces.index', ['release' => R::CURRENT]), false)
        ->assertSee(route('sherlock.issues.index', ['release' => R::CURRENT, 'status' => 'all']));
});

it('marks a sampled comparison as unbiased', function (): void {
    config()->set('sherlock.releases.compare_min_samples', 2);
    app()->forgetInstance(Anabil\Sherlock\Releases\ReleaseComparator::class);
    R::seed();

    $this->get('/sherlock/deploys')
        ->assertOk()
        ->assertSee('sampled')
        ->assertDontSee('Entries marked');
});
