<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Context;

use Anabil\Sherlock\Data\MemoryLeakSignal;
use Closure;

/**
 * Memory leak detection for long-running queue workers. After each job: one memory_get_usage()
 * call into a bounded ring buffer (default 50 jobs). No I/O, no allocation beyond the buffer.
 *
 * The baseline is taken after $warmupJobs jobs (autoloading, opcache, container singletons
 * and static caches fill up during the first jobs). A leak is reported when the memory has
 * grown by at least one more $growthBytes step over the baseline and the growth is steady: at
 * least $minJobs jobs since the baseline, and at least $minGrowingRatio of the jobs in the
 * window grew the memory (a single big allocation, e.g. a cache warmed once, is not a leak).
 * Each step is reported once per worker process; the signal names the job classes that
 * contributed the most growth in the window.
 */
final class WorkerMemoryMonitor
{
    /** Deltas below this are allocator noise, not growth. */
    public const NOISE_BYTES = 1024;

    public const MAX_CONTRIBUTORS = 3;

    /** @var list<array{0: int, 1: string}> ring buffer of [memory after job, job class] */
    private array $samples = [];

    private int $next = 0;

    private int $jobs = 0;

    private ?int $baseline = null;

    private int $reportedStep = 0;

    private readonly Closure $reader;

    private readonly int $size;

    /**
     * @param  (Closure(): int)|null  $reader  memory source (default memory_get_usage()); injectable for tests
     */
    public function __construct(
        private readonly int $growthBytes = 33_554_432,
        private readonly int $minJobs = 20,
        int $bufferSize = 50,
        private readonly int $warmupJobs = 5,
        private readonly float $minGrowingRatio = 0.5,
        ?Closure $reader = null,
    ) {
        $this->size = max(2, $bufferSize);
        $this->reader = $reader ?? static fn (): int => memory_get_usage();
    }

    /**
     * Record the memory after a job; returns a signal when a new threshold step is crossed.
     */
    public function sample(string $jobClass): ?MemoryLeakSignal
    {
        $memory = (int) ($this->reader)();
        $this->jobs++;

        if ($this->baseline === null) {
            if ($this->jobs >= max(1, $this->warmupJobs)) {
                $this->baseline = $memory;
                $this->jobs = 0;
            }

            return null;
        }

        $this->samples[$this->next] = [$memory, $jobClass];
        $this->next = ($this->next + 1) % $this->size;

        if ($this->growthBytes <= 0 || $this->jobs < max(2, $this->minJobs)) {
            return null;
        }

        $step = intdiv(max(0, $memory - $this->baseline), $this->growthBytes);
        if ($step <= $this->reportedStep) {
            return null;
        }

        [$ratio, $contributors] = $this->window();
        if ($ratio < $this->minGrowingRatio) {
            return null;
        }

        $this->reportedStep = $step;

        return new MemoryLeakSignal($this->baseline, $memory, $this->jobs, $step, $ratio, $contributors, $this->growthBytes);
    }

    /** Samples kept (bounded by the buffer size). */
    public function bufferedCount(): int
    {
        return count($this->samples);
    }

    public function baseline(): ?int
    {
        return $this->baseline;
    }

    /**
     * Share of growing jobs in the window and the growth per job class, oldest to newest.
     *
     * @return array{0: float, 1: array<string, int>}
     */
    private function window(): array
    {
        $count = count($this->samples);
        $start = $count < $this->size ? 0 : $this->next;
        $previous = null;
        $growing = 0;
        $deltas = 0;
        $byClass = [];

        for ($i = 0; $i < $count; $i++) {
            [$memory, $class] = $this->samples[($start + $i) % $this->size];

            if ($previous !== null) {
                $delta = $memory - $previous;
                $deltas++;
                if ($delta > self::NOISE_BYTES) {
                    $growing++;
                    $byClass[$class] = ($byClass[$class] ?? 0) + $delta;
                }
            }

            $previous = $memory;
        }

        arsort($byClass);

        return [$deltas === 0 ? 0.0 : $growing / $deltas, array_slice($byClass, 0, self::MAX_CONTRIBUTORS, true)];
    }
}
