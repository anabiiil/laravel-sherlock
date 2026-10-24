<?php

declare(strict_types=1);

namespace Anabil\Tracing\Data;

use Anabil\Tracing\Enums\ExplainInsightType;
use Anabil\Tracing\Enums\Severity;

/**
 * One interpreted finding from an EXPLAIN plan, e.g.
 * "Full table scan on `orders` (≈120k rows); consider index on `orders.user_id`".
 */
final readonly class ExplainInsight
{
    /**
     * @param  string|null  $suggestedIndex  e.g. "orders(user_id)"
     */
    public function __construct(
        public ExplainInsightType $type,
        public Severity $severity,
        public string $message,
        public ?string $table = null,
        public ?string $suggestedIndex = null,
        public ?int $estimatedRows = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'severity' => $this->severity->value,
            'message' => $this->message,
            'table' => $this->table,
            'suggested_index' => $this->suggestedIndex,
            'estimated_rows' => $this->estimatedRows,
        ];
    }
}
