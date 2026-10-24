<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Support;

use Anabil\Sherlock\Contracts\ServerProbe;
use Throwable;

/**
 * ServerProbe on the running host: getrusage(), sys_getloadavg(), /proc/cpuinfo, /proc/meminfo.
 * No shell exec. The core count is memoized per process (static: shared by every app instance
 * of an Octane / queue worker, the hardware does not change).
 */
final class SystemServerProbe implements ServerProbe
{
    /** @var array<string, int|null> path => cores (memoized per process) */
    private static array $cores = [];

    /**
     * @param  int|null  $configuredCores  sherlock.server.cpu_cores (wins over detection)
     */
    public function __construct(
        private readonly bool $cpuTime = true,
        private readonly bool $loadAverage = true,
        private readonly bool $memory = true,
        private readonly ?int $configuredCores = null,
        private readonly string $procPath = '/proc',
    ) {}

    public function cpuTimeMs(): ?float
    {
        if (! $this->cpuTime || ! function_exists('getrusage')) {
            return null;
        }

        $usage = getrusage();
        if (! is_array($usage) || ! isset($usage['ru_utime.tv_sec'], $usage['ru_stime.tv_sec'])) {
            return null;
        }

        return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1000
            + (($usage['ru_utime.tv_usec'] ?? 0) + ($usage['ru_stime.tv_usec'] ?? 0)) / 1000;
    }

    public function loadAverage(): ?float
    {
        if (! $this->loadAverage || ! function_exists('sys_getloadavg')) {
            return null;
        }

        $load = @sys_getloadavg();

        return is_array($load) && $load[0] >= 0 ? round((float) $load[0], 2) : null;
    }

    public function cpuCores(): ?int
    {
        if ($this->configuredCores !== null && $this->configuredCores > 0) {
            return $this->configuredCores;
        }

        if (array_key_exists($this->procPath, self::$cores)) {
            return self::$cores[$this->procPath];
        }

        return self::$cores[$this->procPath] = $this->detectCores();
    }

    public function memoryAvailableMb(): ?int
    {
        if (! $this->memory) {
            return null;
        }

        $meminfo = $this->read($this->procPath.'/meminfo');
        if ($meminfo === null || preg_match('/^MemAvailable:\s+(\d+)\s*kB/mi', $meminfo, $match) !== 1) {
            return null;
        }

        return intdiv((int) $match[1], 1024);
    }

    /** Forget the memoized core count (tests). */
    public static function flush(): void
    {
        self::$cores = [];
    }

    private function detectCores(): ?int
    {
        $cpuinfo = $this->read($this->procPath.'/cpuinfo');
        if ($cpuinfo === null) {
            return null;
        }

        $count = preg_match_all('/^processor\s*:/mi', $cpuinfo);

        return is_int($count) && $count > 0 ? $count : null;
    }

    private function read(string $path): ?string
    {
        try {
            if (! @is_readable($path)) {
                return null;
            }

            $contents = @file_get_contents($path);

            return is_string($contents) && $contents !== '' ? $contents : null;
        } catch (Throwable) {
            return null;
        }
    }
}
