<?php

declare(strict_types=1);

namespace Anabil\Tracing\Contracts;

use Anabil\Tracing\Data\AiAnalysisResult;
use Anabil\Tracing\Data\Finding;
use Anabil\Tracing\Data\IssueFilter;
use Anabil\Tracing\Data\IssueOutcome;
use Anabil\Tracing\Data\IssueRecord;
use Anabil\Tracing\Enums\AiStatus;
use Anabil\Tracing\Enums\IssueStatus;
use DateTimeImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * tracing_issues + tracing_issue_traces on the tracing connection.
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
     */
    public function recordOccurrence(Finding $finding, array $context, DateTimeImmutable $seenAt, ?string $traceId = null): IssueOutcome;

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
     * @return array<string, int> severity value => count
     */
    public function countBySeverity(?IssueStatus $status = IssueStatus::Open): array;

    /** Delete issues last seen before $before (and their trace links), in chunks. */
    public function pruneOlderThan(DateTimeImmutable $before, int $chunkSize = 1000): int;
}
