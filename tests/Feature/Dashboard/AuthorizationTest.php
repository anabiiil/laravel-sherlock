<?php

declare(strict_types=1);

use Anabil\Sherlock\Http\Middleware\Authorize;
use Anabil\Sherlock\Providers\DashboardServiceProvider;
use Anabil\Sherlock\Tests\Feature\Dashboard\DashboardFixtures as D;
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

    $this->get('/sherlock')->assertForbidden();
    $this->get('/sherlock/issues')->assertForbidden();
    $this->post('/sherlock/issues/1/resolve')->assertForbidden();
});

it('allows access in the local environment by default', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    $this->get('/sherlock')->assertOk();
});

it('lets the application override the gate', function (): void {
    Gate::define(Authorize::ABILITY, static fn (?User $user = null): bool => $user?->getAttribute('email') === 'admin@example.com');

    $this->get('/sherlock')->assertForbidden();

    $admin = (new User)->forceFill(['id' => 1, 'email' => 'admin@example.com']);
    $this->actingAs($admin)->get('/sherlock')->assertOk();

    $other = (new User)->forceFill(['id' => 2, 'email' => 'someone@example.com']);
    $this->actingAs($other)->get('/sherlock')->assertForbidden();
});

it('does not replace a gate the application defined first', function (): void {
    Gate::define(Authorize::ABILITY, static fn (mixed $user = null): bool => true);

    // Re-running the provider boot must keep the app's definition.
    (new DashboardServiceProvider(app()))->boot();

    $this->get('/sherlock')->assertOk();
});

it('registers no routes and no gate when the dashboard is disabled', function (): void {
    dashboardRebootWith(['SHERLOCK_DASHBOARD' => 'false']);

    expect(config('sherlock.dashboard.enabled'))->toBeFalse()
        ->and(Route::has('sherlock.overview'))->toBeFalse()
        ->and(Gate::has(Authorize::ABILITY))->toBeFalse()
        ->and(config('sherlock.ignore.routes'))->not->toContain(DashboardServiceProvider::ROUTE_PATTERN);

    D::allowAccess();

    $this->get('/sherlock')->assertNotFound();
    $this->get('/sherlock/issues')->assertNotFound();
    $this->post('/sherlock/issues/1/resolve')->assertNotFound();
});

it('serves the dashboard on a custom path', function (): void {
    dashboardRebootWith(['SHERLOCK_PATH' => 'ops/perf']);
    D::allowAccess();

    $this->get('/ops/perf')->assertOk()->assertSee('/ops/perf/issues');
    $this->get('/ops/perf/traces')->assertOk();
    $this->get('/sherlock')->assertNotFound();

    expect(config('sherlock.ignore.paths'))->toContain('ops/perf', 'ops/perf/*')
        ->and(config('sherlock.ignore.routes'))->toContain(DashboardServiceProvider::ROUTE_PATTERN);
});

it('applies the configured middleware to every dashboard route', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'sherlock.'));

    expect($routes)->toHaveCount(11);

    foreach ($routes as $route) {
        expect($route->gatherMiddleware())->toContain('web', Authorize::class);
    }
});
