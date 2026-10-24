<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Data;

use Anabil\Sherlock\Enums\AiStatus;
use DateTimeImmutable;

/**
 * Outcome of AI analysis for one issue (stored on the issue and reused).
 */
final readonly class AiAnalysisResult
{
    /**
     * @param  bool  $fromCache  true when an existing stored analysis was reused
     */
    public function __construct(
        public AiStatus $status,
        public ?string $analysis = null,
        public ?string $model = null,
        public ?string $error = null,
        public ?DateTimeImmutable $analyzedAt = null,
        public bool $fromCache = false,
    ) {}

    public static function done(string $analysis, string $model, ?DateTimeImmutable $at = null, bool $fromCache = false): self
    {
        return new self(AiStatus::Done, $analysis, $model, null, $at ?? new DateTimeImmutable, $fromCache);
    }

    public static function failed(string $error, ?string $model = null): self
    {
        return new self(AiStatus::Failed, null, $model, $error, new DateTimeImmutable);
    }

    public static function skipped(AiStatus $status): self
    {
        return new self($status);
    }

    public function isDone(): bool
    {
        return $this->status === AiStatus::Done;
    }
}
