<?php

declare(strict_types=1);

namespace Anabil\Tracing\Issues;

use Anabil\Tracing\Contracts\IssueRepository;
use Anabil\Tracing\Contracts\IssueService;
use Anabil\Tracing\Data\Finding;
use Anabil\Tracing\Data\IssueOutcome;
use Anabil\Tracing\Data\RecordedTrace;
use Anabil\Tracing\Enums\IssueStatus;
use Anabil\Tracing\Enums\OccurrenceType;
use Anabil\Tracing\Events\IssueDetected;
use Anabil\Tracing\Events\IssueRecurred;
use Anabil\Tracing\Events\IssueRegressed;
use Anabil\Tracing\Jobs\AnalyzeIssueJob;
use Anabil\Tracing\Jobs\NotifyIssueJob;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Findings -> issues. One upsert per distinct issue fingerprint in the trace, one capped
 * trace link per issue, then events:
 *  - New       -> IssueDetected  (+ AnalyzeIssueJob -> NotifyIssueJob chain)
 *  - Regressed -> IssueRegressed (+ the same chain: AI re-analyzes regressions)
 *  - Recurring -> IssueRecurred  (throttled re-notification hook)
 * Ignored issues only count occurrences: no link, no events, no jobs.
 */
final class DefaultIssueService implements IssueService
{
    /**
     * @param  bool  $dispatchJobs  dispatch the AI/notification chain (false when both features are off: no wasted jobs)
     */
    public function __construct(
        private readonly IssueRepository $issues,
        private readonly Dispatcher $events,
        private readonly IssueContextBuilder $contexts = new IssueContextBuilder,
        private readonly ?Bus $bus = null,
        private readonly int $traceCap = 10,
        private readonly bool $dispatchJobs = false,
    ) {}

    public function recordFindings(string $traceId, RecordedTrace $trace): array
    {
        $outcomes = [];
        $seenAt = $trace->trace->startedAt;

        foreach ($this->distinct($trace->analysis->findings) as $finding) {
            $outcome = $this->issues->recordOccurrence(
                $finding,
                $this->contexts->build($finding, $trace, $traceId),
                $seenAt,
                $traceId,
            );

            $outcomes[] = $outcome;

            if ($outcome->isIgnored()) {
                continue;
            }

            if ($this->hasId($outcome)) {
                $this->issues->attachTrace($outcome->issueId, $traceId, $this->traceCap);
            }

            $this->announce($outcome);
        }

        return $outcomes;
    }

    public function changeStatus(int|string $issueId, IssueStatus $status): void
    {
        $this->issues->updateStatus($issueId, $status);
    }

    private function announce(IssueOutcome $outcome): void
    {
        $this->events->dispatch(match ($outcome->occurrence) {
            OccurrenceType::New => new IssueDetected($outcome),
            OccurrenceType::Regressed => new IssueRegressed($outcome),
            OccurrenceType::Recurring => new IssueRecurred($outcome),
        });

        if ($outcome->occurrence->isNoteworthy() && $this->dispatchJobs && $this->bus !== null && $this->hasId($outcome)) {
            $this->bus->dispatch(
                (new AnalyzeIssueJob($outcome->issueId, $outcome->occurrence === OccurrenceType::Regressed))
                    ->chain([new NotifyIssueJob($outcome->issueId)])
            );
        }
    }

    /**
     * One finding per issue fingerprint (the most severe), so a trace never counts twice.
     *
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    private function distinct(array $findings): array
    {
        $byFingerprint = [];

        foreach ($findings as $finding) {
            $existing = $byFingerprint[$finding->fingerprint] ?? null;

            if ($existing === null || $finding->severity->isHigherThan($existing->severity)) {
                $byFingerprint[$finding->fingerprint] = $finding;
            }
        }

        return array_values($byFingerprint);
    }

    private function hasId(IssueOutcome $outcome): bool
    {
        return $outcome->issueId !== 0 && $outcome->issueId !== '';
    }
}
