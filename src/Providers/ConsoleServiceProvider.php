<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Providers;

use Anabil\Sherlock\Console\InstallCommand;
use Anabil\Sherlock\Console\ReportCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Owns the general artisan commands: sherlock:install and sherlock:report (console only).
 * Feature commands are registered by their own providers: watch / status (capture),
 * prune / clear (storage), analyze (AI), test-notification (notifications), digest (digest).
 */
final class ConsoleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([InstallCommand::class, ReportCommand::class]);
    }
}
