<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Issues;

use Anabil\Sherlock\Ai\AiEligibility;
use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Contracts\IssueService;
use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Data\IssueOutcome;
use Anabil\Sherlock\Data\RecordedTrace;
use Anabil\Sherlock\Enums\AiStatus;
use Anabil\Sherlock\Enums\IssueStatus;
use Anabil\Sherlock\Enums\OccurrenceType;
use Anabil\Sherlock\Events\IssueDetected;
use Anabil\Sherlock\Events\IssueRecurred;
use Anabil\Sherlock\Events\IssueRegressed;
use Anabil\Sherlock\Jobs\AnalyzeIssueJob;
use Anabil\Sherlock\Jobs\NotifyIssueJob;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Findings -> issues. One upsert per distinct issue fingerprint in the trace, one capped
 * trace link per issue, then events:
 *  - New       -> IssueDetected  (+ AnalyzeIssueJob -> NotifyIssueJob chain)
 *  - Regressed -> IssueRegressed (+ the same chain: AI re-analyzes regressions)
 *  - Recurring -> IssueRecurred  (throttled re-notification hook)
 * Ignored issues only count occurrences: no link, no events, no jobs.
 *
 * The ai_status stored for a new / regressed issue reflects what will happen: skipped_disabled
 * or skipped_severity (AiEligibility, config only), pending only when an analysis job is
 * actually dispatched, null ("not analyzed") when AI is on but no job runs here (sync queue
 * inside an HTTP request; sherlock:analyze / Re-analyze still work).
 */
final class DefaultIssueService implements IssueService
{
    /**
     * @param  bool  $dispatchJobs  dispatch the AI/notification chain (false when both features are off: no wasted jobs)
     * @param  bool  $analyze  include the AI job; false dispatches the notification job alone (e.g. a sync
     *                         queue inside an HTTP request, where the AI call would hold the PHP worker)
     * @param  AiEligibility|null  $ai  AI config rules for the initial ai_status; null = AI disabled
     */
    public function __construct(
        private readonly IssueRepository $issues,
        private readonly Dispatcher $events,
        private readonly IssueContextBuilder $contexts = new IssueContextBuilder,
        private readonly ?Bus $bus = null,
        private readonly int $traceCap = 10,
        private readonly bool $dispatchJobs = false,
        private readonly bool $analyze = true,
        private readonly bool $notify = true,
        private readonly ?AiEligibility $ai = null,
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
                $this->initialAiStatus($finding),
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

    private function initialAiStatus(Finding $finding): ?AiStatus
    {
        $skip = $this->ai === null ? AiStatus::SkippedDisabled : $this->ai->skipReason($finding->type, $finding->severity);
        if ($skip !== null) {
            return $skip;
        }

        return $this->dispatchJobs && $this->analyze && $this->bus !== null ? AiStatus::Pending : null;
    }

    private function announce(IssueOutcome $outcome): void
    {
        $this->events->dispatch(match ($outcome->occurrence) {
            OccurrenceType::New => new IssueDetected($outcome),
            OccurrenceType::Regressed => new IssueRegressed($outcome),
            OccurrenceType::Recurring => new IssueRecurred($outcome),
        });

        if (! $outcome->occurrence->isNoteworthy() || ! $this->dispatchJobs || $this->bus === null || ! $this->hasId($outcome)) {
            return;
        }

        if ($this->analyze) {
            $this->bus->dispatch(
                (new AnalyzeIssueJob($outcome->issueId, $outcome->occurrence === OccurrenceType::Regressed))
                    ->chain([new NotifyIssueJob($outcome->issueId)])
            );
        } elseif ($this->notify) {
            $this->bus->dispatch(new NotifyIssueJob($outcome->issueId));
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
