<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Tests\Feature\Integration\Support;

use Anabil\Sherlock\Contracts\SourceLocator;
use Anabil\Sherlock\Support\BacktraceSourceLocator;
use Illuminate\Contracts\Foundation\Application;

/**
 * Testbench's application base path is its skeleton under vendor/, so every frame of
 * this package's test fixtures would be "outside the application" and no source location
 * would ever be found. The integration suites treat the package root as the application
 * root instead: tests/... become application files, src/ stays "the package" and vendor/
 * stays vendor, exactly like app/... in a real project.
 *
 * Runs while the application is created (after register, before boot), so the collectors
 * built at boot receive this locator.
 */
trait BootsSherlock
{
    protected function getEnvironmentSetUp($app): void
    {
        /** @var Application $app */
        $app->singleton(SourceLocator::class, static fn (): BacktraceSourceLocator => new BacktraceSourceLocator(
            Integration::packageRoot(),
            50,
        ));
    }
}
