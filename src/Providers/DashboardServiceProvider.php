<?php

declare(strict_types=1);

namespace Anabil\Tracing\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Owns the dashboard: routes, viewTracing gate, controllers, views (loadViewsFrom "tracing").
 */
final class DashboardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
