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
 * Peak memory of a unit >= warningMb (critical >= criticalMb).
 */
final class HighMemoryAnalyzer implements Analyzer
{
    public function __construct(
        private readonly Fingerprinter $fingerprinter,
        private readonly float $warningMb = 128,
        private readonly float $criticalMb = 512,
    ) {}

    public function analyze(TraceData $trace): array
    {
        $peakMb = $trace->memoryPeakMb();
        if ($trace->memoryPeakBytes <= 0 || $peakMb < $this->warningMb) {
            return [];
        }

        $entry = Describe::entry($trace);

        return [new Finding(
            type: IssueType::HighMemory,
            severity: $peakMb >= $this->criticalMb ? Severity::Critical : Severity::Warning,
            title: 'High memory usage in '.$entry.' ('.Describe::megabytes($trace->memoryPeakBytes).' peak)',
            description: sprintf(
                'Peak memory reached %s (threshold %dMB) with %d queries in this %s.',
                Describe::megabytes($trace->memoryPeakBytes),
                (int) $this->warningMb,
                $trace->queryCount(),
                strtolower($trace->type->label()),
            ),
            fingerprint: $this->fingerprinter->issue(IssueType::HighMemory, null, null, $entry),
            metrics: [
                'memory_peak_mb' => round($peakMb, 1),
                'memory_peak_bytes' => $trace->memoryPeakBytes,
                'threshold_mb' => $this->warningMb,
            ],
            recommendation: 'Avoid loading whole result sets into memory: iterate with chunkById(), lazy() or cursor(), '
                .'select only needed columns, stream large files and exports instead of building them in memory, '
                .'and unset large arrays or collections once they are no longer needed.',
        )];
    }
}
