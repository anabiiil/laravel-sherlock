<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Contracts;

/**
 * Cheap server signals ("is it the query or the server?"). Never shells out; every method
 * returns null when the signal is unavailable on this platform or disabled.
 *
 * Cost contract: cpuTimeMs() is one getrusage() call (unit start / end only); loadAverage()
 * one sys_getloadavg() call (unit end); cpuCores() is resolved once per process; memoryAvailableMb()
 * reads /proc/meminfo and is only called for traces that are persisted.
 */
interface ServerProbe
{
    /** User + system CPU time consumed by this process so far, in ms. */
    public function cpuTimeMs(): ?float;

    /** 1-minute system load average. */
    public function loadAverage(): ?float;

    /** CPU cores of the server (config override, else Linux /proc/cpuinfo), memoized per process. */
    public function cpuCores(): ?int;

    /** Available system memory in MB (Linux MemAvailable). */
    public function memoryAvailableMb(): ?int;
}
