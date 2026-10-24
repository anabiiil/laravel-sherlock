<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Notifications;

use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Data\IssueOutcome;
use Anabil\Sherlock\Data\IssueRecord;
use Anabil\Sherlock\Enums\IssueStatus;
use Anabil\Sherlock\Notifications\Jobs\SendThrottledSummaryJob;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * Per-fingerprint throttling of repeat notifications.
 *
 * The first occurrence (and a regression) is sent immediately by the AnalyzeIssueJob ->
 * NotifyIssueJob chain. Every repeat only increments pending_notify_count; one summary job per
 * throttle window ("occurred N more times in the last X minutes") is scheduled for
 * last_notified_at + throttle_minutes, guarded by an atomic Cache::add keyed by
 * fingerprint + window so concurrent workers never schedule it twice. The summary job calls
 * markNotified, which resets the count and opens the next window.
 *
 * With a sync queue (delays impossible) the summary is dispatched lazily, by the first repeat
 * seen after the window has elapsed.
 */
final class Throttler
{
    /** Some drivers (SQS) cap delays at 15 minutes; longer waits re-dispatch in steps. */
    public const MAX_DELAY_SECONDS = 900;

    public function __construct(
        private readonly IssueRepository $issues,
        private readonly NotificationPolicy $policy,
        private readonly DefaultNotificationDispatcher $dispatcher,
        private readonly IssueMessageFactory $messages,
        private readonly Cache $cache,
        private readonly Bus $bus,
    ) {}

    /**
     * Count a repeat occurrence; schedule the window's summary if none is scheduled yet.
     * Returns whether this call scheduled the summary job.
     */
    public function recordRepeat(IssueOutcome $outcome): bool
    {
        if ($outcome->isIgnored() || ! $this->tracks($outcome)) {
            return false;
        }

        $pending = $this->issues->incrementPendingNotifications($outcome->issueId);
        if ($pending <= 0) {
            return false;
        }

        $issue = $this->issues->find($outcome->issueId);
        if ($issue === null || $issue->status !== IssueStatus::Open) {
            return false;
        }

        $wait = $this->secondsUntilDue($issue);

        if ($wait > 0 && $this->policy->queueIsSync()) {
            return false; // lazy mode: a later repeat dispatches once the window has elapsed
        }

        if (! $this->claim($issue, $wait)) {
            return false;
        }

        $this->dispatchSummary($issue->id, $wait);

        return true;
    }

    /**
     * Send the summary for an issue whose window has elapsed (SendThrottledSummaryJob).
     * Returns whether a summary was sent.
     *
     * @param  bool  $canWait  whether the job may re-dispatch itself with a delay when not due yet
     */
    public function flush(int|string $issueId, bool $canWait = false): bool
    {
        $issue = $this->issues->find($issueId);
        if ($issue === null) {
            return false;
        }

        if ($issue->status !== IssueStatus::Open || $issue->pendingNotifyCount <= 0
            || ! $this->policy->allowsType($issue->type, $issue->regressedAt !== null)) {
            $this->release($issue);

            return false;
        }

        $wait = $this->secondsUntilDue($issue);
        if ($wait > 0) {
            if ($canWait) {
                $this->dispatchSummary($issue->id, $wait); // keep the claim, keep waiting
            } else {
                $this->release($issue);
            }

            return false;
        }

        $now = CarbonImmutable::now();
        $minutes = (int) max(1, (int) ceil(($now->getTimestamp() - $this->reference($issue)->getTimestamp()) / 60));

        $results = $this->dispatcher->deliver($this->messages->summary($issue, $issue->pendingNotifyCount, $minutes));

        $this->issues->markNotified($issue->id, $now);
        $this->release($issue);

        return $results !== [];
    }

    /** Start of the current window: the last notification, or the first sighting if none yet. */
    public function reference(IssueRecord $issue): DateTimeImmutable
    {
        return $issue->lastNotifiedAt ?? $issue->firstSeenAt;
    }

    public function dueAt(IssueRecord $issue): DateTimeImmutable
    {
        return $this->reference($issue)->modify('+'.$this->policy->throttleMinutes().' minutes');
    }

    public function secondsUntilDue(IssueRecord $issue): int
    {
        return max(0, $this->dueAt($issue)->getTimestamp() - CarbonImmutable::now()->getTimestamp());
    }

    public function lockKey(IssueRecord $issue): string
    {
        return 'sherlock:notifications:summary:'.$issue->fingerprint.':'.$this->reference($issue)->getTimestamp();
    }

    /** Cheap pre-checks (config only) so untracked repeats cost no query. */
    private function tracks(IssueOutcome $outcome): bool
    {
        return $this->policy->allowsType($outcome->issueType) && $this->dispatcher->accepts($outcome->severity);
    }

    private function claim(IssueRecord $issue, int $wait): bool
    {
        // Held until the summary is sent; expires on its own if the job is lost.
        $ttl = $wait + $this->policy->throttleMinutes() * 60;

        try {
            return $this->cache->add($this->lockKey($issue), 1, $ttl);
        } catch (Throwable) {
            return false; // no reliable guard: skip rather than risk duplicate summaries
        }
    }

    private function release(IssueRecord $issue): void
    {
        try {
            $this->cache->forget($this->lockKey($issue));
        } catch (Throwable) {
            // Expires on its own.
        }
    }

    private function dispatchSummary(int|string $issueId, int $wait): void
    {
        $job = new SendThrottledSummaryJob($issueId);

        if ($wait > 0) {
            $job->delay(min($wait, self::MAX_DELAY_SECONDS));
        }

        $this->bus->dispatch($job);
    }
}
