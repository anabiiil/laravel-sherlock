<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Data;

use Anabil\Sherlock\Enums\AiStatus;
use Anabil\Sherlock\Enums\IssueStatus;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;
use DateTimeImmutable;

/**
 * Read model of a row in sherlock_issues.
 */
final readonly class IssueRecord
{
    /**
     * @param  array<string, mixed>  $context  latest Finding::toArray() plus entry info / verdict / explain
     * @param  float  $impactMs  cumulative time cost of every occurrence (Finding::$impactMs summed)
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
        public ?string $firstSeenRelease = null,
        public ?string $lastSeenRelease = null,
        public float $impactMs = 0.0,
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
            'first_seen_release' => $this->firstSeenRelease,
            'last_seen_release' => $this->lastSeenRelease,
            'impact_ms' => $this->impactMs,
        ];
    }
}
