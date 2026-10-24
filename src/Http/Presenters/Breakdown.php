<?php

declare(strict_types=1);

namespace Anabil\Tracing\Http\Presenters;

use Anabil\Tracing\Data\StoredTrace;
use Anabil\Tracing\Data\Verdict;
use Anabil\Tracing\Http\Support\Value;

/**
 * Time split of a trace (db / http / cache / bootstrap / app) as stacked-bar segments.
 * Percentages are normalized so the bar never exceeds 100% (db and http time can overlap).
 */
final readonly class Breakdown
{
    private const LABELS = [
        Verdict::SEGMENT_DB => 'Database',
        Verdict::SEGMENT_EXTERNAL_HTTP => 'HTTP',
        Verdict::SEGMENT_CACHE => 'Cache',
        Verdict::SEGMENT_BOOTSTRAP => 'Bootstrap',
        Verdict::SEGMENT_APPLICATION => 'App',
    ];

    private const COLORS = [
        Verdict::SEGMENT_DB => 'bg-sky-500',
        Verdict::SEGMENT_EXTERNAL_HTTP => 'bg-violet-500',
        Verdict::SEGMENT_CACHE => 'bg-amber-500',
        Verdict::SEGMENT_BOOTSTRAP => 'bg-zinc-400',
        Verdict::SEGMENT_APPLICATION => 'bg-emerald-500',
    ];

    /**
     * @param  list<array{key: string, label: string, ms: float, percent: float, color: string}>  $segments
     */
    private function __construct(
        public array $segments,
        public float $totalMs,
    ) {}

    /**
     * @param  array<array-key, mixed>  $verdict  Verdict::toArray()
     */
    public static function fromVerdict(array $verdict, float $fallbackTotalMs = 0.0): self
    {
        $ms = [];
        foreach (Value::array($verdict['breakdown_ms'] ?? null) as $segment => $value) {
            if (isset(self::LABELS[$segment])) {
                $ms[(string) $segment] = Value::float($value);
            }
        }

        $total = Value::float($verdict['total_ms'] ?? null);

        return self::make($ms, $total > 0 ? $total : $fallbackTotalMs);
    }

    /**
     * The stored verdict breakdown when present, otherwise derived from the trace columns.
     */
    public static function fromTrace(StoredTrace $trace): self
    {
        $verdict = Value::array($trace->details['verdict'] ?? null);

        if (Value::array($verdict['breakdown_ms'] ?? null) !== []) {
            return self::fromVerdict($verdict, $trace->durationMs);
        }

        $cache = Value::float($trace->details['cache_ms'] ?? null);
        $known = $trace->dbMs + $trace->httpMs + $cache + $trace->bootstrapMs;

        return self::make([
            Verdict::SEGMENT_DB => $trace->dbMs,
            Verdict::SEGMENT_EXTERNAL_HTTP => $trace->httpMs,
            Verdict::SEGMENT_CACHE => $cache,
            Verdict::SEGMENT_BOOTSTRAP => $trace->bootstrapMs,
            Verdict::SEGMENT_APPLICATION => max(0.0, $trace->durationMs - $known),
        ], $trace->durationMs);
    }

    public function isEmpty(): bool
    {
        return $this->segments === [];
    }

    /**
     * @param  array<string, float>  $ms
     */
    private static function make(array $ms, float $total): self
    {
        $ms = array_filter($ms, static fn (float $value): bool => $value > 0);
        $denominator = max($total, array_sum($ms));

        if ($denominator <= 0) {
            return new self([], max(0.0, $total));
        }

        $segments = [];
        foreach (self::LABELS as $key => $label) {
            if (! isset($ms[$key])) {
                continue;
            }

            $segments[] = [
                'key' => $key,
                'label' => $label,
                'ms' => $ms[$key],
                'percent' => round($ms[$key] / $denominator * 100, 1),
                'color' => self::COLORS[$key],
            ];
        }

        return new self($segments, $total);
    }
}
