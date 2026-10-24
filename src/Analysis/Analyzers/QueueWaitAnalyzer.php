<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Analysis\Analyzers;

use Anabil\Sherlock\Analysis\Support\Describe;
use Anabil\Sherlock\Contracts\Analyzer;
use Anabil\Sherlock\Contracts\Fingerprinter;
use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;

/**
 * A queue job that waited >= warningMs between becoming available (push + delay) and starting
 * (critical >= criticalMs). One issue per connection + queue: the workers of that queue cannot
 * keep up. Impact: the wait.
 */
final class QueueWaitAnalyzer implements Analyzer
{
    public function __construct(
        private readonly Fingerprinter $fingerprinter,
        private readonly float $warningMs = 30000,
        private readonly float $criticalMs = 300000,
    ) {}

    public function analyze(TraceData $trace): array
    {
        $wait = $trace->queueWaitMs;
        if ($trace->type !== EntryType::Job || $wait === null || $wait < $this->warningMs) {
            return [];
        }

        $queue = self::meta($trace, 'queue') ?? 'default';
        $connection = self::meta($trace, 'connection');

        return [new Finding(
            type: IssueType::QueueWait,
            severity: $wait >= $this->criticalMs ? Severity::Critical : Severity::Warning,
            title: sprintf('Job waited %s in queue `%s` before starting — workers can\'t keep up', Describe::duration($wait), $queue),
            description: sprintf(
                '%s waited %s in the `%s` queue%s before a worker picked it up (threshold %s), then ran for %s. '
                .'Jobs are pushed faster than the workers of this queue process them, or long jobs block the workers.',
                $trace->name,
                Describe::duration($wait),
                $queue,
                $connection !== null ? ' ('.$connection.')' : '',
                Describe::duration($this->warningMs),
                Describe::duration($trace->durationMs),
            ),
            fingerprint: $this->fingerprinter->issue(IssueType::QueueWait, null, null, 'queue:'.($connection ?? '').':'.$queue),
            metrics: [
                'wait_ms' => round($wait, 1),
                'threshold_ms' => $this->warningMs,
                'duration_ms' => round($trace->durationMs, 2),
                'queue' => $queue,
                'connection' => $connection,
                'job' => $trace->name,
            ],
            recommendation: 'Run more workers for this queue (or let Horizon balance them: balance => auto with a higher maxProcesses), '
                .'move slow or bulk jobs to a separate queue with its own workers so they cannot starve latency-sensitive jobs, '
                .'and check for jobs that hold workers for a long time (timeouts, external calls, sleep / release loops).',
            impactMs: round($wait, 3),
        )];
    }

    private static function meta(TraceData $trace, string $key): ?string
    {
        $value = $trace->metadata[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
