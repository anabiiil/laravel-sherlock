<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Analysis\Analyzers;

use Anabil\Sherlock\Analysis\DefaultBottleneckClassifier;
use Anabil\Sherlock\Analysis\Support\Describe;
use Anabil\Sherlock\Contracts\Analyzer;
use Anabil\Sherlock\Contracts\Fingerprinter;
use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;

/**
 * A slow entry (over its sherlock.thresholds.entries warning) that ran while the server's load
 * average per CPU core was >= warningPerCore (critical >= criticalPerCore). One issue per server
 * (host name), since the cause is the machine, not the entry point.
 *
 * Impact: the part of the application segment not spent on this process's own CPU time (the
 * time it waited for a CPU), when the CPU time is known.
 */
final class ServerLoadAnalyzer implements Analyzer
{
    private static ?string $host = null;

    /** @var array<string, float> entry type => warning ms */
    private readonly array $slowMs;

    /**
     * @param  array<array-key, mixed>  $entryThresholds  sherlock.thresholds.entries
     */
    public function __construct(
        private readonly Fingerprinter $fingerprinter,
        private readonly float $warningPerCore = 1.5,
        private readonly float $criticalPerCore = 3.0,
        array $entryThresholds = SlowEntryAnalyzer::DEFAULTS,
    ) {
        $slow = [];
        foreach (SlowEntryAnalyzer::DEFAULTS as $type => $defaults) {
            $given = is_array($entryThresholds[$type] ?? null) ? $entryThresholds[$type] : [];
            $slow[$type] = is_numeric($given['warning_ms'] ?? null) ? (float) $given['warning_ms'] : (float) $defaults['warning_ms'];
        }
        $this->slowMs = $slow;
    }

    public function analyze(TraceData $trace): array
    {
        $perCore = $trace->loadPerCore();
        $slowMs = $this->slowMs[$trace->type->value];

        if ($perCore === null || $perCore < $this->warningPerCore || $trace->durationMs < $slowMs) {
            return [];
        }

        $host = self::host();
        $entry = Describe::entry($trace);
        $application = max(0.0, $trace->durationMs - $trace->dbMs - $trace->httpMs - $trace->cacheMs - $trace->bootstrapMs);
        $impact = $trace->cpuMs !== null ? max(0.0, $application - $trace->cpuMs) : 0.0;

        return [new Finding(
            type: IssueType::ServerLoad,
            severity: $perCore >= $this->criticalPerCore ? Severity::Critical : Severity::Warning,
            title: sprintf(
                'Server overloaded: load %s on %d cores (%s) during %s (%s)',
                round((float) $trace->loadAvg, 2),
                (int) $trace->cpuCores,
                Describe::percent($perCore),
                $entry,
                Describe::duration($trace->durationMs),
            ),
            description: DefaultBottleneckClassifier::serverLoadReason($trace, $perCore, $trace->durationMs)
                .' When every core is busy, requests queue for CPU time: the code or query may be fine, the server is not.',
            fingerprint: $this->fingerprinter->issue(IssueType::ServerLoad, null, null, 'server:'.$host),
            metrics: [
                'load_avg' => $trace->loadAvg,
                'cpu_cores' => $trace->cpuCores,
                'load_per_core' => round($perCore, 3),
                'threshold_per_core' => $this->warningPerCore,
                'cpu_ms' => $trace->cpuMs !== null ? round($trace->cpuMs, 2) : null,
                'duration_ms' => round($trace->durationMs, 2),
                'memory_free_mb' => $trace->memoryFreeMb,
                'host' => $host,
            ],
            recommendation: 'Find what saturates the CPUs (top / htop: other processes, queue workers or cron jobs on the same host, '
                .'PHP-FPM pm.max_children set higher than the cores can serve). Scale out or up, move queue workers and heavy '
                .'scheduled tasks to another server, and size pm.max_children / worker counts to the available cores.',
            impactMs: round($impact, 3),
        )];
    }

    private static function host(): string
    {
        if (self::$host === null) {
            $host = gethostname();
            self::$host = is_string($host) && $host !== '' ? $host : 'server';
        }

        return self::$host;
    }
}
