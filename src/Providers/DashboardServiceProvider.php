<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Providers;

use Anabil\Sherlock\Http\Middleware\Authorize;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Owns the dashboard: routes, viewSherlock gate, controllers, views (loadViewsFrom "sherlock").
 *
 * When "sherlock.dashboard.enabled" is false nothing is registered (no routes, no views, no
 * gate). Otherwise the cost on non-dashboard requests is the route registration only;
 * controllers, presenters and views load when a dashboard route is hit.
 *
 * Access: the "viewSherlock" gate, defined here only when the app has not defined it, allows
 * the "local" environment only. Override it in your AppServiceProvider::boot():
 *
 *     Gate::define('viewSherlock', fn ($user) => in_array($user->email, ['me@example.com'], true));
 */
final class DashboardServiceProvider extends ServiceProvider
{
    /** Dashboard route names; added to "sherlock.ignore.routes" so the dashboard is never traced. */
    public const ROUTE_PATTERN = 'sherlock.*';

    public function register(): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->excludeFromRecording();
    }

    public function boot(): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'sherlock');

        $this->callAfterResolving(Gate::class, static function (Gate $gate, Application $app): void {
            if (! $gate->has(Authorize::ABILITY)) {
                $gate->define(Authorize::ABILITY, static fn (mixed $user = null): bool => $app->environment('local'));
            }
        });

        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $config = $this->config();
        $domain = $config->get('sherlock.dashboard.domain');
        $middleware = $config->get('sherlock.dashboard.middleware', ['web']);

        $this->app->make(Router::class)->group([
            'domain' => is_string($domain) && $domain !== '' ? $domain : null,
            'prefix' => $this->path(),
            'middleware' => [...(is_array($middleware) ? array_values($middleware) : ['web']), Authorize::class],
            'as' => 'sherlock.',
        ], __DIR__.'/../Http/routes.php');
    }

    /**
     * The default ignore list covers "sherlock*" only; a custom path or domain would be traced.
     * Ignoring the route names covers every case; ignoring the path too skips the work earlier.
     */
    private function excludeFromRecording(): void
    {
        $config = $this->config();

        $routes = $config->get('sherlock.ignore.routes', []);
        $routes = is_array($routes) ? $routes : [];
        if (! in_array(self::ROUTE_PATTERN, $routes, true)) {
            $config->set('sherlock.ignore.routes', [...$routes, self::ROUTE_PATTERN]);
        }

        $path = $this->path();
        if ($path === '') {
            // Root of a dedicated domain: never add a catch-all path pattern.
            return;
        }

        $paths = $config->get('sherlock.ignore.paths', []);
        $paths = is_array($paths) ? $paths : [];
        foreach ($paths as $pattern) {
            if (is_string($pattern) && Str::is($pattern, $path) && Str::is($pattern, $path.'/issues')) {
                return;
            }
        }

        $config->set('sherlock.ignore.paths', [...$paths, $path, $path.'/*']);
    }

    private function path(): string
    {
        $path = $this->config()->get('sherlock.dashboard.path', 'sherlock');

        return trim(is_string($path) ? $path : 'sherlock', '/');
    }

    private function enabled(): bool
    {
        return (bool) $this->config()->get('sherlock.dashboard.enabled', true);
    }

    private function config(): Repository
    {
        return $this->app->make(Repository::class);
    }
}
