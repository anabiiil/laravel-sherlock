<?php

declare(strict_types=1);

use Anabil\Tracing\Http\Middleware\Authorize;
use Anabil\Tracing\Providers\DashboardServiceProvider;
use Anabil\Tracing\Tests\Feature\Dashboard\DashboardFixtures as D;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Rebuild the application with env overrides (config is read while providers register).
 *
 * @param  array<string, string>  $env
 */
function dashboardRebootWith(array $env): void
{
    foreach ($env as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    test()->dashboardEnv = array_keys($env);
    test()->refreshApplication();
    D::web();
}

afterEach(function (): void {
    foreach ($this->dashboardEnv ?? [] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
});

beforeEach(fn () => D::web());

it('denies access outside the local environment by default', function (): void {
    expect(app()->environment())->toBe('testing');

    $this->get('/tracing')->assertForbidden();
    $this->get('/tracing/issues')->assertForbidden();
    $this->post('/tracing/issues/1/resolve')->assertForbidden();
});

it('allows access in the local environment by default', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    $this->get('/tracing')->assertOk();
});

it('lets the application override the gate', function (): void {
    Gate::define(Authorize::ABILITY, static fn (?User $user = null): bool => $user?->getAttribute('email') === 'admin@example.com');

    $this->get('/tracing')->assertForbidden();

    $admin = (new User)->forceFill(['id' => 1, 'email' => 'admin@example.com']);
    $this->actingAs($admin)->get('/tracing')->assertOk();

    $other = (new User)->forceFill(['id' => 2, 'email' => 'someone@example.com']);
    $this->actingAs($other)->get('/tracing')->assertForbidden();
});

it('does not replace a gate the application defined first', function (): void {
    Gate::define(Authorize::ABILITY, static fn (mixed $user = null): bool => true);

    // Re-running the provider boot must keep the app's definition.
    (new DashboardServiceProvider(app()))->boot();

    $this->get('/tracing')->assertOk();
});

it('registers no routes and no gate when the dashboard is disabled', function (): void {
    dashboardRebootWith(['TRACING_DASHBOARD' => 'false']);

    expect(config('tracing.dashboard.enabled'))->toBeFalse()
        ->and(Route::has('tracing.overview'))->toBeFalse()
        ->and(Gate::has(Authorize::ABILITY))->toBeFalse()
        ->and(config('tracing.ignore.routes'))->not->toContain(DashboardServiceProvider::ROUTE_PATTERN);

    D::allowAccess();

    $this->get('/tracing')->assertNotFound();
    $this->get('/tracing/issues')->assertNotFound();
    $this->post('/tracing/issues/1/resolve')->assertNotFound();
});

it('serves the dashboard on a custom path', function (): void {
    dashboardRebootWith(['TRACING_PATH' => 'ops/perf']);
    D::allowAccess();

    $this->get('/ops/perf')->assertOk()->assertSee('/ops/perf/issues');
    $this->get('/ops/perf/traces')->assertOk();
    $this->get('/tracing')->assertNotFound();

    expect(config('tracing.ignore.paths'))->toContain('ops/perf', 'ops/perf/*')
        ->and(config('tracing.ignore.routes'))->toContain(DashboardServiceProvider::ROUTE_PATTERN);
});

it('applies the configured middleware to every dashboard route', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'tracing.'));

    expect($routes)->toHaveCount(10);

    foreach ($routes as $route) {
        expect($route->gatherMiddleware())->toContain('web', Authorize::class);
    }
});
