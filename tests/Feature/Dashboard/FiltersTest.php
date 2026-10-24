<?php

declare(strict_types=1);

use Anabil\Tracing\Enums\Bottleneck;
use Anabil\Tracing\Enums\EntryType;
use Anabil\Tracing\Enums\IssueStatus;
use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Enums\Severity;
use Anabil\Tracing\Tests\Feature\Dashboard\DashboardFixtures as D;
use Carbon\CarbonImmutable;

beforeEach(fn () => D::allowAccess());

it('filters issues by type, severity, status and search', function (): void {
    D::issueWith('fp-a', 'Slow query on orders', IssueType::SlowQuery, Severity::Warning);
    D::issueWith('fp-b', 'N+1 on comments', IssueType::NPlusOne, Severity::Critical);
    $resolved = D::issueWith('fp-c', 'Exception in billing', IssueType::Exception, Severity::Critical);
    app(Anabil\Tracing\Contracts\IssueRepository::class)->updateStatus($resolved->id, IssueStatus::Resolved);

    // Default: open issues only.
    $this->get('/tracing/issues')->assertOk()
        ->assertSee('Slow query on orders')->assertSee('N+1 on comments')->assertDontSee('Exception in billing');

    $this->get('/tracing/issues?status=all')->assertOk()->assertSee('Exception in billing');
    $this->get('/tracing/issues?status=resolved')->assertOk()
        ->assertSee('Exception in billing')->assertDontSee('Slow query on orders');

    $this->get('/tracing/issues?type=n_plus_one')->assertOk()
        ->assertSee('N+1 on comments')->assertDontSee('Slow query on orders');

    $this->get('/tracing/issues?severity=warning')->assertOk()
        ->assertSee('Slow query on orders')->assertDontSee('N+1 on comments');

    $this->get('/tracing/issues?search=orders')->assertOk()
        ->assertSee('Slow query on orders')->assertDontSee('N+1 on comments');

    $this->get('/tracing/issues?search=fp-b')->assertOk()
        ->assertSee('N+1 on comments')->assertDontSee('Slow query on orders');
});

it('ignores invalid issue filters instead of failing', function (): void {
    D::issueWith('fp-a', 'Slow query on orders');

    $this->get('/tracing/issues?type=nope&severity[]=x&status=weird&sort=drop+table&page=-3')
        ->assertOk()
        ->assertSee('Slow query on orders');
});

it('sorts issues by occurrences', function (): void {
    D::issueWith('fp-few', 'Rare issue');
    D::issueWith('fp-many', 'Frequent issue');
    D::issueWith('fp-many', 'Frequent issue');
    D::issueWith('fp-many', 'Frequent issue');

    $this->get('/tracing/issues?sort=occurrences')->assertOk()->assertSeeInOrder(['Frequent issue', 'Rare issue']);
});

it('paginates issues and keeps the filters in the page links', function (): void {
    config()->set('tracing.dashboard.per_page', 2);

    foreach (range(1, 5) as $i) {
        D::issueWith("fp-{$i}", "Paged issue {$i}", severity: Severity::Critical, at: CarbonImmutable::now()->subMinutes(10 - $i));
    }

    $this->get('/tracing/issues?severity=critical')->assertOk()
        ->assertSee('Showing 1–2 of 5')
        ->assertSee('Paged issue 5')
        ->assertDontSee('Paged issue 3')
        ->assertSee('severity=critical&amp;page=2', false);

    $this->get('/tracing/issues?severity=critical&page=3')->assertOk()
        ->assertSee('Paged issue 1')
        ->assertSee('Showing 5–5 of 5')
        ->assertDontSee('Paged issue 5');
});

it('filters traces by type, name, bottleneck, minimum duration and issue', function (): void {
    D::trace('GET /fast', durationMs: 50.0, bottleneck: Bottleneck::Application);
    D::trace('GET /slow', durationMs: 2500.0, bottleneck: Bottleneck::Database);
    D::trace('App\\Jobs\\SendMail', EntryType::Job, 300.0, Bottleneck::ExternalService);
    $seeded = D::seed(name: 'GET /issue-trace');

    $this->get('/tracing/traces?type=job')->assertOk()
        ->assertSee('App\\Jobs\\SendMail')->assertDontSee('GET /fast');

    $this->get('/tracing/traces?name='.urlencode('GET /slow'))->assertOk()
        ->assertSee('GET /slow')->assertDontSee('GET /fast');

    $this->get('/tracing/traces?bottleneck=application')->assertOk()
        ->assertSee('GET /fast')->assertDontSee('GET /slow');

    $this->get('/tracing/traces?min_ms=1000')->assertOk()
        ->assertSee('GET /slow')->assertSee('GET /issue-trace')->assertDontSee('GET /fast');

    $this->get('/tracing/traces?issue='.$seeded['slow']->id)->assertOk()
        ->assertSee('GET /issue-trace')->assertSee('Example traces of')->assertDontSee('GET /fast');

    $this->get('/tracing/traces?sort=duration')->assertOk()->assertSeeInOrder(['GET /slow', 'GET /issue-trace', 'App\\Jobs\\SendMail', 'GET /fast']);
});

it('paginates traces and keeps the filters in the page links', function (): void {
    config()->set('tracing.dashboard.per_page', 2);

    foreach (range(1, 3) as $i) {
        D::trace("GET /page-{$i}", at: CarbonImmutable::now()->subMinutes(10 - $i));
    }

    $this->get('/tracing/traces?type=request')->assertOk()
        ->assertSee('GET /page-3')->assertDontSee('GET /page-1')
        ->assertSee('type=request&amp;page=2', false);

    $this->get('/tracing/traces?type=request&page=2')->assertOk()->assertSee('GET /page-1')->assertDontSee('GET /page-3');
});

it('orders top queries and honours the period', function (): void {
    D::seed();
    D::trace('GET /old', at: CarbonImmutable::now()->subDays(10));

    $this->get('/tracing/queries?order=count')->assertOk()->assertSeeInOrder(['fp-posts', 'fp-users']);
    $this->get('/tracing/queries?order=total')->assertOk()->assertSeeInOrder(['fp-users', 'fp-posts']);
    $this->get('/tracing/queries?order=avg&period=1h')->assertOk()->assertSee('fp-users');
    $this->get('/tracing/queries?order=bogus')->assertOk()->assertSee('Total time');
});
