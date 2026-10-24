<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Testing;

use Anabil\Sherlock\Analysis\Analyzers\DuplicateQueryAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\ExcessiveQueriesAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\NPlusOneAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\SlowQueryAnalyzer;
use Anabil\Sherlock\Contracts\Fingerprinter;
use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Data\QueryRecord;
use Anabil\Sherlock\Data\TraceData;

/**
 * What a QueryProbe saw while its callback ran: the callback's return value, every query
 * (normalized + fingerprinted, with bindings and source location) and, on demand, the
 * findings of the built-in query analyzers. Every finder takes an optional threshold
 * overriding the configured one (ProbeThresholds).
 */
final class ProbeResult
{
    /**
     * @param  TraceData  $trace  queries already normalized + fingerprinted
     */
    public function __construct(
        public readonly mixed $value,
        public readonly TraceData $trace,
        public readonly ProbeThresholds $thresholds,
        private readonly Fingerprinter $fingerprinter,
    ) {}

    /**
     * @return list<QueryRecord>
     */
    public function queries(): array
    {
        return $this->trace->queries;
    }

    public function count(): int
    {
        return count($this->trace->queries);
    }

    public function totalMs(): float
    {
        return $this->trace->dbMs;
    }

    /**
     * Same query + same source line repeated >= $threshold times.
     *
     * @return list<Finding>
     */
    public function nPlusOnes(?int $threshold = null): array
    {
        $threshold = max(2, $threshold ?? $this->thresholds->nPlusOne);

        return (new NPlusOneAnalyzer($this->fingerprinter, $threshold, max($threshold, 20)))->analyze($this->trace);
    }

    /**
     * Identical SQL + identical bindings executed >= $minCount times.
     *
     * @return list<Finding>
     */
    public function duplicates(?int $minCount = null): array
    {
        return (new DuplicateQueryAnalyzer($this->fingerprinter, max(2, $minCount ?? $this->thresholds->duplicates)))->analyze($this->trace);
    }

    /**
     * Queries taking >= $ms milliseconds.
     *
     * @return list<Finding>
     */
    public function slowQueries(?float $ms = null): array
    {
        $ms = max(0.0, $ms ?? $this->thresholds->slowQueryMs);

        return (new SlowQueryAnalyzer($this->fingerprinter, $ms, max($ms, 1000.0)))->analyze($this->trace);
    }

    /**
     * One finding when the callback ran >= $max queries.
     *
     * @return list<Finding>
     */
    public function excessiveQueries(?int $max = null): array
    {
        $max = max(1, $max ?? $this->thresholds->maxQueries);

        return (new ExcessiveQueriesAnalyzer($this->fingerprinter, $max, max($max, 200)))->analyze($this->trace);
    }

    /**
     * Every query issue with the configured thresholds: N+1, duplicates, slow queries, too many queries.
     *
     * @return list<Finding>
     */
    public function findings(): array
    {
        return [...$this->nPlusOnes(), ...$this->duplicates(), ...$this->slowQueries(), ...$this->excessiveQueries()];
    }

    public function hasIssues(): bool
    {
        return $this->findings() !== [];
    }
}
