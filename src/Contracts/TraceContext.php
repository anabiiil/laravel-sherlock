<?php

declare(strict_types=1);

namespace Anabil\Tracing\Contracts;

use Anabil\Tracing\Data\HttpCallRecord;
use Anabil\Tracing\Data\LogRecord;
use Anabil\Tracing\Data\QueryRecord;
use Anabil\Tracing\Data\TraceData;
use Anabil\Tracing\Enums\EntryType;
use Anabil\Tracing\Enums\RecordReason;

/**
 * Mutable per-unit buffer (one request / job / command). Collectors write into it;
 * at terminate it is frozen into a TraceData. NullTraceContext is used when nothing
 * is being recorded, so collectors never need "if enabled" checks.
 */
interface TraceContext
{
    public function id(): string;

    public function type(): ?EntryType;

    /** Whether records are being collected. */
    public function isActive(): bool;

    /** Full capture: bindings and source locations are collected. */
    public function isFull(): bool;

    /** Milliseconds since the unit started (for record offsets). */
    public function elapsedMs(): float;

    public function addQuery(QueryRecord $query): void;

    public function addHttpCall(HttpCallRecord $call): void;

    public function addLog(LogRecord $log): void;

    public function cacheHit(): void;

    public function cacheMiss(): void;

    public function cacheWrite(): void;

    public function setName(string $name): void;

    public function setRoute(?string $route): void;

    public function setStatusCode(?int $statusCode): void;

    public function markFailed(): void;

    public function setUserId(int|string|null $userId): void;

    public function setBootstrapMs(float $ms): void;

    public function addRecordReason(RecordReason $reason): void;

    public function tag(string $key, string|int|float|bool|null $value): void;

    public function meta(string $key, string|int|float|bool|null $value): void;

    /**
     * Whether a source location was already captured for this query fingerprint key
     * in this trace (first-occurrence backtrace rule). Marks it as seen.
     */
    public function firstOccurrence(string $key): bool;

    /** Freeze into an immutable snapshot (duration / memory measured now). */
    public function toTraceData(): TraceData;
}
