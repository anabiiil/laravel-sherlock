<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Contracts;

use Anabil\Sherlock\Data\AiAnalysisResult;
use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Data\IssueFilter;
use Anabil\Sherlock\Data\IssueOutcome;
use Anabil\Sherlock\Data\IssueRecord;
use Anabil\Sherlock\Enums\AiStatus;
use Anabil\Sherlock\Enums\IssueStatus;
use DateTimeImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * sherlock_issues + sherlock_issue_traces on the Sherlock connection.
 */
interface IssueRepository
{
    public function find(int|string $id): ?IssueRecord;

    public function findByFingerprint(string $fingerprint): ?IssueRecord;

    /**
     * Atomic upsert by fingerprint: creates the issue (New), increments occurrences (Recurring),
     * or reopens a resolved issue and sets regressed_at (Regressed). Ignored issues only
     * count occurrences. Severity only escalates.
     *
     * @param  array<string, mixed>  $context  merged into the issue context (entry, verdict, explain...)
     * @param  AiStatus|null  $aiStatus  ai_status of a new issue and of a regression (null = not analyzed);
     *                                   a regression keeps a stored analysis unless it is set to pending
     * @param  string|null  $release  release of the occurrence: first_seen_release of a new issue, last_seen_release always
     *
     * impact_ms is incremented by $finding->impactMs in the same atomic UPDATE (no extra query).
     */
    public function recordOccurrence(Finding $finding, array $context, DateTimeImmutable $seenAt, ?string $traceId = null, ?AiStatus $aiStatus = AiStatus::Pending, ?string $release = null): IssueOutcome;

    /** Link an example trace, keeping at most $cap most recent links. */
    public function attachTrace(int|string $issueId, string $traceId, int $cap = 10): void;

    /**
     * @return list<string> most recent example trace ids
     */
    public function traceIds(int|string $issueId, int $limit = 10): array;

    /**
     * @return LengthAwarePaginator<int, IssueRecord>
     */
    public function paginate(IssueFilter $filter, int $perPage = 25, int $page = 1): LengthAwarePaginator;

    public function updateStatus(int|string $id, IssueStatus $status, ?DateTimeImmutable $at = null): void;

    /** Set last_notified_at and reset pending_notify_count. */
    public function markNotified(int|string $id, DateTimeImmutable $at): void;

    /** Increment pending_notify_count; returns the new value. */
    public function incrementPendingNotifications(int|string $id): int;

    public function updateAiStatus(int|string $id, AiStatus $status): void;

    /** Store ai_status / ai_analysis / ai_model / ai_analyzed_at. */
    public function saveAiAnalysis(int|string $id, AiAnalysisResult $result): void;

    /**
     * @return list<IssueRecord>
     */
    public function newSince(DateTimeImmutable $since, int $limit = 10): array;

    /**
     * @return list<IssueRecord>
     */
    public function regressedSince(DateTimeImmutable $since, int $limit = 10): array;

    /**
     * Seen since $since, ordered by occurrences.
     *
     * @return list<IssueRecord>
     */
    public function mostFrequent(DateTimeImmutable $since, int $limit = 10): array;

    /**
     * Seen since $since, ordered by cumulative impact_ms (all-time total of the issue, not only
     * the time since $since: per-occurrence history is not stored).
     *
     * @return list<IssueRecord>
     */
    public function topByImpact(DateTimeImmutable $since, int $limit = 10): array;

    /**
     * Issues first seen in $release (new in that deploy), most impactful first.
     *
     * @return list<IssueRecord>
     */
    public function firstSeenInRelease(string $release, int $limit = 50): array;

    /**
     * Issues that regressed (came back after being resolved) while $release was running.
     *
     * @return list<IssueRecord>
     */
    public function regressedInRelease(string $release, int $limit = 50): array;

    /**
     * Issues whose last occurrence was in $release (e.g. the previous release: not seen since, maybe fixed).
     *
     * @return list<IssueRecord>
     */
    public function lastSeenInRelease(string $release, int $limit = 50): array;

    /**
     * @return array<string, int> severity value => count
     */
    public function countBySeverity(?IssueStatus $status = IssueStatus::Open): array;

    /** Delete issues last seen before $before (and their trace links), in chunks. */
    public function pruneOlderThan(DateTimeImmutable $before, int $chunkSize = 1000): int;
}
