<?php

declare(strict_types=1);

use Anabil\Tracing\Tests\Feature\Dashboard\DashboardFixtures as D;
use Carbon\CarbonImmutable;

beforeEach(fn () => D::allowAccess());

it('renders every list page with empty data', function (string $uri, string $empty): void {
    $this->get($uri)
        ->assertOk()
        ->assertSee('Tracing')
        ->assertSee($empty);
})->with([
    'overview' => ['/tracing', 'No traces in this period.'],
    'issues' => ['/tracing/issues', 'No issues match these filters.'],
    'traces' => ['/tracing/traces', 'No traces match these filters.'],
    'queries' => ['/tracing/queries', 'No queries recorded in this period.'],
]);

it('returns 404 for unknown issues and traces', function (): void {
    $this->get('/tracing/issues/999999')->assertNotFound();
    $this->get('/tracing/traces/01UNKNOWNTRACE000000000000')->assertNotFound();
});

it('renders the overview with seeded data', function (): void {
    D::seed();

    $this->get('/tracing')
        ->assertOk()
        ->assertSee('N+1 queries on posts')
        ->assertSee('Slow query on users')
        ->assertSee('GET /users')
        ->assertSee('select * from posts where user_id = ?')
        ->assertSee('Database');
});

it('switches the overview period', function (): void {
    D::seed(CarbonImmutable::now()->subDays(3));

    $this->get('/tracing?period=24h')->assertOk()->assertSee('No traces in this period.');
    $this->get('/tracing?period=7d')->assertOk()->assertDontSee('No traces in this period.')->assertSee('GET /users');
    $this->get('/tracing?period=bogus')->assertOk()->assertSee('Last 24 hours');
});

it('renders the issues list and the issue detail page', function (): void {
    $seeded = D::seed();
    D::analyze($seeded['slow'], "## Root cause\n\nMissing index on `users.email`.\n\n- Add the index");

    $this->get('/tracing/issues')
        ->assertOk()
        ->assertSee('Slow query on users')
        ->assertSee('N+1 queries on posts');

    $this->get('/tracing/issues/'.$seeded['slow']->id)
        ->assertOk()
        ->assertSee('Slow query on users')
        ->assertSee('Warning')
        ->assertSee('Open')
        ->assertSee('Full table scan on `users`')
        ->assertSee('users(email)')
        ->assertSee('app/Http/Controllers/UserController.php:42')
        ->assertSee('72% of 250ms spent in 1 query')
        ->assertSee('Add an index on users.email')
        ->assertSee('<h2>Root cause</h2>', false)
        ->assertSee('<code>users.email</code>', false)
        ->assertSee('test-model')
        ->assertSee('/tracing/traces/'.$seeded['trace'])
        ->assertSee('name="_token"', false);
});

it('renders the trace detail page with timeline, N+1 group, http calls and logs', function (): void {
    $seeded = D::seed();

    $this->get('/tracing/traces/'.$seeded['trace'])
        ->assertOk()
        ->assertSee('GET /users')
        ->assertSee('Timeline')
        ->assertSee('N+1')
        ->assertSee('fp-posts')
        ->assertSee('app/Http/Controllers/UserController.php:50')
        ->assertSee('https://api.example.com/charge?key=[REDACTED]')
        ->assertSee('RuntimeException')
        ->assertSee('Something failed')
        ->assertSee('tenant: acme')
        ->assertSee('7 stored of 7 executed')
        ->assertSee('/tracing/issues/'.$seeded['nPlusOne']->id);
});

it('renders the traces list and the queries page with seeded data', function (): void {
    D::seed();

    $this->get('/tracing/traces')->assertOk()->assertSee('GET /users')->assertSee('1.25 s');

    $this->get('/tracing/queries')
        ->assertOk()
        ->assertSee('fp-users')
        ->assertSee('posts');
});

it('uses the configured dashboard view namespace', function (): void {
    expect(view()->exists('tracing::dashboard.overview'))->toBeTrue()
        ->and(route('tracing.overview', [], false))->toBe('/tracing')
        ->and(route('tracing.issues.show', ['issue' => 5], false))->toBe('/tracing/issues/5');
});
