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
 * Cache miss ratio, evaluated only with >= minLookups lookups:
 * info >= infoRatio, warning >= warningRatio.
 */
final class CacheMissRateAnalyzer implements Analyzer
{
    public function __construct(
        private readonly Fingerprinter $fingerprinter,
        private readonly int $minLookups = 10,
        private readonly float $infoRatio = 0.5,
        private readonly float $warningRatio = 0.8,
    ) {}

    public function analyze(TraceData $trace): array
    {
        $lookups = $trace->cacheLookups();
        if ($lookups < $this->minLookups) {
            return [];
        }

        $ratio = $trace->cacheMissRatio();
        if ($ratio < $this->infoRatio) {
            return [];
        }

        $entry = Describe::entry($trace);

        return [new Finding(
            type: IssueType::CacheMissRate,
            severity: $ratio >= $this->warningRatio ? Severity::Warning : Severity::Info,
            title: 'High cache miss rate in '.$entry.' ('.Describe::percent($ratio).' of '.$lookups.' lookups)',
            description: sprintf(
                '%d of %d cache lookups missed (%s, threshold %s) and %d values were written.',
                $trace->cacheMisses,
                $lookups,
                Describe::percent($ratio),
                Describe::percent($this->infoRatio),
                $trace->cacheWrites,
            ),
            fingerprint: $this->fingerprinter->issue(IssueType::CacheMissRate, null, null, $entry),
            metrics: [
                'lookups' => $lookups,
                'hits' => $trace->cacheHits,
                'misses' => $trace->cacheMisses,
                'writes' => $trace->cacheWrites,
                'miss_ratio' => round($ratio, 3),
            ],
            recommendation: 'Check that cache keys are stable (no timestamps, random values or per-request ids in the key), '
                .'that TTLs are not too short, and that the cache is not flushed too often. Use Cache::remember() so a miss '
                .'fills the cache, and warm frequently used keys after deploys.',
        )];
    }
}
