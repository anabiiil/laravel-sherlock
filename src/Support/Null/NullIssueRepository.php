<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Support\Null;

use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Data\AiAnalysisResult;
use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Data\IssueFilter;
use Anabil\Sherlock\Data\IssueOutcome;
use Anabil\Sherlock\Data\IssueRecord;
use Anabil\Sherlock\Enums\AiStatus;
use Anabil\Sherlock\Enums\IssueStatus;
use Anabil\Sherlock\Enums\OccurrenceType;
use DateTimeImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;

final class NullIssueRepository implements IssueRepository
{
    public function find(int|string $id): ?IssueRecord
    {
        return null;
    }

    public function findByFingerprint(string $fingerprint): ?IssueRecord
    {
        return null;
    }

    public function recordOccurrence(Finding $finding, array $context, DateTimeImmutable $seenAt, ?string $traceId = null, ?AiStatus $aiStatus = AiStatus::Pending): IssueOutcome
    {
        return new IssueOutcome(0, $finding->fingerprint, $finding->type, $finding->severity, OccurrenceType::New, 1, $traceId);
    }

    public function attachTrace(int|string $issueId, string $traceId, int $cap = 10): void {}

    public function traceIds(int|string $issueId, int $limit = 10): array
    {
        return [];
    }

    /**
     * @return LengthAwarePaginatorContract<int, IssueRecord>
     */
    public function paginate(IssueFilter $filter, int $perPage = 25, int $page = 1): LengthAwarePaginatorContract
    {
        /** @var LengthAwarePaginator<int, IssueRecord> */
        return new LengthAwarePaginator([], 0, $perPage, $page);
    }

    public function updateStatus(int|string $id, IssueStatus $status, ?DateTimeImmutable $at = null): void {}

    public function markNotified(int|string $id, DateTimeImmutable $at): void {}

    public function incrementPendingNotifications(int|string $id): int
    {
        return 0;
    }

    public function updateAiStatus(int|string $id, AiStatus $status): void {}

    public function saveAiAnalysis(int|string $id, AiAnalysisResult $result): void {}

    public function newSince(DateTimeImmutable $since, int $limit = 10): array
    {
        return [];
    }

    public function regressedSince(DateTimeImmutable $since, int $limit = 10): array
    {
        return [];
    }

    public function mostFrequent(DateTimeImmutable $since, int $limit = 10): array
    {
        return [];
    }

    public function countBySeverity(?IssueStatus $status = IssueStatus::Open): array
    {
        return [];
    }

    public function pruneOlderThan(DateTimeImmutable $before, int $chunkSize = 1000): int
    {
        return 0;
    }
}
