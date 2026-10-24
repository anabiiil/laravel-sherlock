<?php

declare(strict_types=1);

namespace Anabil\Tracing\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Owns storage: repositories, persistence strategies (after_response / queue), IssueService, models on the tracing connection, retention scheduling.
 */
final class StorageServiceProvider extends ServiceProvider
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
