<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Analysis\Analyzers;

use Anabil\Sherlock\Analysis\Support\Describe;
use Anabil\Sherlock\Contracts\Analyzer;
use Anabil\Sherlock\Contracts\Fingerprinter;
use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;

/**
 * Turns the WorkerMemoryMonitor signal attached to a job trace into a finding: a queue worker
 * whose memory grew steadily across consecutive jobs (critical once the growth reaches
 * criticalMb). One issue per connection + queue.
 */
final class MemoryLeakAnalyzer implements Analyzer
{
    public function __construct(
        private readonly Fingerprinter $fingerprinter,
        private readonly float $criticalMb = 128,
    ) {}

    public function analyze(TraceData $trace): array
    {
        $signal = $trace->memoryLeak;
        if ($signal === null) {
            return [];
        }

        $growth = $signal->growthBytes();
        $queue = is_string($trace->metadata['queue'] ?? null) ? (string) $trace->metadata['queue'] : 'default';
        $connection = is_string($trace->metadata['connection'] ?? null) ? (string) $trace->metadata['connection'] : '';

        $contributors = [];
        foreach ($signal->contributors as $class => $bytes) {
            $contributors[] = $class.' (+'.self::size($bytes).')';
        }

        return [new Finding(
            type: IssueType::MemoryLeak,
            severity: $growth >= $this->criticalMb * 1048576 ? Severity::Critical : Severity::Warning,
            title: sprintf('Memory leak in queue worker `%s`: +%s over %d jobs', $queue, self::size($growth), $signal->jobs),
            description: sprintf(
                'The worker memory grew steadily from %s to %s over %d jobs (%s of the recent jobs increased it)%s. '
                .'A long-running worker never frees memory that is still referenced, so it grows until it is killed.',
                self::size($signal->baselineBytes),
                self::size($signal->currentBytes),
                $signal->jobs,
                Describe::percent($signal->growingRatio),
                $contributors === [] ? '' : '; most growth after: '.implode(', ', $contributors),
            ),
            fingerprint: $this->fingerprinter->issue(IssueType::MemoryLeak, null, null, 'worker:'.$connection.':'.$queue),
            metrics: [
                'growth_mb' => round($growth / 1048576, 1),
                'baseline_mb' => round($signal->baselineBytes / 1048576, 1),
                'current_mb' => round($signal->currentBytes / 1048576, 1),
                'jobs' => $signal->jobs,
                'step' => $signal->step,
                'growing_ratio' => round($signal->growingRatio, 3),
                'top_job' => array_key_first($signal->contributors),
                'queue' => $queue,
            ],
            recommendation: 'Restart workers regularly as a safety net (queue:work --max-jobs=1000 --memory=256, or Horizon\'s maxJobs / memory). '
                .'Then find what keeps growing: static or singleton caches filled per job, event listeners or macros registered inside '
                .'handle(), query logs left enabled (DB::enableQueryLog()), growing arrays on long-lived services, and models or '
                .'collections kept in properties of singletons.',
        )];
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).'MB' : round($bytes / 1024).'KB';
    }
}
