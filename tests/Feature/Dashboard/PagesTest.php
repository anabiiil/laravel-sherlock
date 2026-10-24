<?php

declare(strict_types=1);

use Anabil\Sherlock\Tests\Feature\Dashboard\DashboardFixtures as D;
use Carbon\CarbonImmutable;

beforeEach(fn () => D::allowAccess());

it('renders every list page with empty data', function (string $uri, string $empty): void {
    $this->get($uri)
        ->assertOk()
        ->assertSee('Sherlock')
        ->assertSee($empty);
})->with([
    'overview' => ['/sherlock', 'No traces in this period.'],
    'issues' => ['/sherlock/issues', 'No issues match these filters.'],
    'traces' => ['/sherlock/traces', 'No traces match these filters.'],
    'queries' => ['/sherlock/queries', 'No queries recorded in this period.'],
]);

it('returns 404 for unknown issues and traces', function (): void {
    $this->get('/sherlock/issues/999999')->assertNotFound();
    $this->get('/sherlock/traces/01UNKNOWNTRACE000000000000')->assertNotFound();
});

it('renders the overview with seeded data', function (): void {
    D::seed();

    $this->get('/sherlock')
        ->assertOk()
        ->assertSee('N+1 queries on posts')
        ->assertSee('Slow query on users')
        ->assertSee('GET /users')
        ->assertSee('select * from posts where user_id = ?')
        ->assertSee('Database');
});

it('switches the overview period', function (): void {
    D::seed(CarbonImmutable::now()->subDays(3));

    $this->get('/sherlock?period=24h')->assertOk()->assertSee('No traces in this period.');
    $this->get('/sherlock?period=7d')->assertOk()->assertDontSee('No traces in this period.')->assertSee('GET /users');
    $this->get('/sherlock?period=bogus')->assertOk()->assertSee('Last 24 hours');
});

it('renders the issues list and the issue detail page', function (): void {
    $seeded = D::seed();
    D::analyze($seeded['slow'], "## Root cause\n\nMissing index on `users.email`.\n\n- Add the index");

    $this->get('/sherlock/issues')
        ->assertOk()
        ->assertSee('Slow query on users')
        ->assertSee('N+1 queries on posts');

    $this->get('/sherlock/issues/'.$seeded['slow']->id)
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
        ->assertSee('/sherlock/traces/'.$seeded['trace'])
        ->assertSee('name="_token"', false);
});

it('renders the trace detail page with timeline, N+1 group, http calls and logs', function (): void {
    $seeded = D::seed();

    $this->get('/sherlock/traces/'.$seeded['trace'])
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
        ->assertSee('/sherlock/issues/'.$seeded['nPlusOne']->id);
});

it('renders the traces list and the queries page with seeded data', function (): void {
    D::seed();

    $this->get('/sherlock/traces')->assertOk()->assertSee('GET /users')->assertSee('1.25 s');

    $this->get('/sherlock/queries')
        ->assertOk()
        ->assertSee('fp-users')
        ->assertSee('posts');
});

it('uses the configured dashboard view namespace', function (): void {
    expect(view()->exists('sherlock::dashboard.overview'))->toBeTrue()
        ->and(route('sherlock.overview', [], false))->toBe('/sherlock')
        ->and(route('sherlock.issues.show', ['issue' => 5], false))->toBe('/sherlock/issues/5');
});
