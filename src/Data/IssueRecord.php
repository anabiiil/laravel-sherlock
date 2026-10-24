<?php

declare(strict_types=1);

namespace Anabil\Tracing\Data;

use Anabil\Tracing\Enums\AiStatus;
use Anabil\Tracing\Enums\IssueStatus;
use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Enums\Severity;
use DateTimeImmutable;

/**
 * Read model of a row in tracing_issues.
 */
final readonly class IssueRecord
{
    /**
     * @param  array<string, mixed>  $context  latest Finding::toArray() plus entry info / verdict / explain
     */
    public function __construct(
        public int|string $id,
        public string $fingerprint,
        public IssueType $type,
        public Severity $severity,
        public IssueStatus $status,
        public string $title,
        public array $context,
        public int $occurrences,
        public DateTimeImmutable $firstSeenAt,
        public DateTimeImmutable $lastSeenAt,
        public ?DateTimeImmutable $resolvedAt = null,
        public ?DateTimeImmutable $regressedAt = null,
        public ?DateTimeImmutable $lastNotifiedAt = null,
        public int $pendingNotifyCount = 0,
        public ?AiStatus $aiStatus = null,
        public ?string $aiAnalysis = null,
        public ?string $aiModel = null,
        public ?DateTimeImmutable $aiAnalyzedAt = null,
    ) {}

    public function hasAiAnalysis(): bool
    {
        return $this->aiStatus === AiStatus::Done && $this->aiAnalysis !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'fingerprint' => $this->fingerprint,
            'type' => $this->type->value,
            'severity' => $this->severity->value,
            'status' => $this->status->value,
            'title' => $this->title,
            'context' => $this->context,
            'occurrences' => $this->occurrences,
            'first_seen_at' => $this->firstSeenAt->format(DATE_ATOM),
            'last_seen_at' => $this->lastSeenAt->format(DATE_ATOM),
            'resolved_at' => $this->resolvedAt?->format(DATE_ATOM),
            'regressed_at' => $this->regressedAt?->format(DATE_ATOM),
            'last_notified_at' => $this->lastNotifiedAt?->format(DATE_ATOM),
            'pending_notify_count' => $this->pendingNotifyCount,
            'ai_status' => $this->aiStatus?->value,
            'ai_analysis' => $this->aiAnalysis,
            'ai_model' => $this->aiModel,
            'ai_analyzed_at' => $this->aiAnalyzedAt?->format(DATE_ATOM),
        ];
    }
}
