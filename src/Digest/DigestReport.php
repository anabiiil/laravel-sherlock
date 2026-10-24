<?php

declare(strict_types=1);

namespace Anabil\Tracing\Digest;

use Anabil\Tracing\Data\EntryStat;
use Anabil\Tracing\Data\IssueRecord;
use Anabil\Tracing\Data\QueryStat;
use Anabil\Tracing\Enums\Severity;
use DateTimeImmutable;

/**
 * Everything a digest covers, read from storage only (no AI calls): the period summary,
 * new / regressed / most frequent issues (with their stored AI analysis), slowest entries,
 * top query fingerprints and the bottleneck distribution.
 */
final readonly class DigestReport
{
    /**
     * @param  array<string, int>  $openBySeverity  severity value => open issue count (all time)
     * @param  list<IssueRecord>  $newIssues
     * @param  list<IssueRecord>  $regressedIssues
     * @param  list<IssueRecord>  $frequentIssues
     * @param  list<EntryStat>  $slowestEntries
     * @param  list<QueryStat>  $topQueries
     * @param  array<string, int>  $bottlenecks  bottleneck value => trace count
     */
    public function __construct(
        public DateTimeImmutable $since,
        public DateTimeImmutable $until,
        public int $traceCount,
        public array $openBySeverity,
        public array $newIssues,
        public array $regressedIssues,
        public array $frequentIssues,
        public array $slowestEntries,
        public array $topQueries,
        public array $bottlenecks,
    ) {}

    public function openIssues(): int
    {
        return array_sum($this->openBySeverity);
    }

    /**
     * Highest severity among open issues (Info when nothing is open).
     */
    public function severity(): Severity
    {
        foreach ([Severity::Critical, Severity::Warning] as $severity) {
            if (($this->openBySeverity[$severity->value] ?? 0) > 0) {
                return $severity;
            }
        }

        return Severity::Info;
    }

    /** Nothing was recorded and nothing is open. */
    public function isQuiet(): bool
    {
        return $this->traceCount === 0
            && $this->openIssues() === 0
            && $this->newIssues === []
            && $this->regressedIssues === []
            && $this->frequentIssues === [];
    }
}
