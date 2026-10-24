<?php

declare(strict_types=1);

namespace Anabil\Tracing\Data;

use Anabil\Tracing\Enums\Bottleneck;

/**
 * Where the time of a trace went and what the bottleneck is.
 */
final readonly class Verdict
{
    public const SEGMENT_DB = 'db';

    public const SEGMENT_EXTERNAL_HTTP = 'external_http';

    public const SEGMENT_CACHE = 'cache';

    public const SEGMENT_BOOTSTRAP = 'bootstrap';

    public const SEGMENT_APPLICATION = 'application';

    public const SEGMENTS = [
        self::SEGMENT_DB,
        self::SEGMENT_EXTERNAL_HTTP,
        self::SEGMENT_CACHE,
        self::SEGMENT_BOOTSTRAP,
        self::SEGMENT_APPLICATION,
    ];

    /**
     * @param  string  $reason  human readable, e.g. "72% of 2.4s spent in 38 queries; top query 900ms on `orders`"
     * @param  array<string, float>  $breakdownMs  segment => ms (keys from SEGMENTS)
     * @param  array<string, float>  $percentages  segment => 0..100
     */
    public function __construct(
        public Bottleneck $bottleneck,
        public string $reason,
        public float $totalMs = 0.0,
        public array $breakdownMs = [],
        public array $percentages = [],
    ) {}

    public static function unknown(string $reason = 'Not enough data to classify.'): self
    {
        return new self(Bottleneck::Unknown, $reason);
    }

    public function ms(string $segment): float
    {
        return $this->breakdownMs[$segment] ?? 0.0;
    }

    public function percentage(string $segment): float
    {
        return $this->percentages[$segment] ?? 0.0;
    }

    /**
     * @return array{bottleneck: string, reason: string, total_ms: float, breakdown_ms: array<string, float>, percentages: array<string, float>}
     */
    public function toArray(): array
    {
        return [
            'bottleneck' => $this->bottleneck->value,
            'reason' => $this->reason,
            'total_ms' => $this->totalMs,
            'breakdown_ms' => $this->breakdownMs,
            'percentages' => $this->percentages,
        ];
    }
}
