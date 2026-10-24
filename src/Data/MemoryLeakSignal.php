<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Data;

/**
 * A queue worker whose memory grew steadily across consecutive jobs (WorkerMemoryMonitor).
 * Attached to the trace of the job that crossed the threshold step.
 */
final readonly class MemoryLeakSignal
{
    /**
     * @param  int  $baselineBytes  memory_get_usage() after the warm-up jobs
     * @param  int  $currentBytes  memory_get_usage() after the job that crossed the step
     * @param  int  $jobs  jobs processed by the worker since the baseline
     * @param  int  $step  threshold step crossed (1 = growth_mb, 2 = 2 x growth_mb...)
     * @param  float  $growingRatio  share of recent jobs that grew memory (0..1)
     * @param  array<string, int>  $contributors  job class => bytes of growth in the recent window, largest first
     */
    public function __construct(
        public int $baselineBytes,
        public int $currentBytes,
        public int $jobs,
        public int $step,
        public float $growingRatio,
        public array $contributors = [],
        public int $thresholdBytes = 0,
    ) {}

    public function growthBytes(): int
    {
        return max(0, $this->currentBytes - $this->baselineBytes);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'baseline_bytes' => $this->baselineBytes,
            'current_bytes' => $this->currentBytes,
            'growth_bytes' => $this->growthBytes(),
            'jobs' => $this->jobs,
            'step' => $this->step,
            'growing_ratio' => round($this->growingRatio, 3),
            'threshold_bytes' => $this->thresholdBytes,
            'contributors' => $this->contributors,
        ];
    }
}
