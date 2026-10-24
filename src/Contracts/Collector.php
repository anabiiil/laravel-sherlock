<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Contracts;

/**
 * Listens to framework events and pushes cheap records into the current TraceContext.
 * register() is called once at boot, never when mode is "off". Listeners must do
 * O(1) work (array pushes); anything heavy belongs to terminate-time analysis.
 */
interface Collector
{
    public function register(): void;
}
