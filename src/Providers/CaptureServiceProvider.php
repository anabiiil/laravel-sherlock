<?php

declare(strict_types=1);

namespace Anabil\Tracing\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Owns capture: ContextManager, collectors, recording policies, watch window, header trigger, request/job/command lifecycle hooks. Must register nothing when mode is "off".
 */
final class CaptureServiceProvider extends ServiceProvider
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
