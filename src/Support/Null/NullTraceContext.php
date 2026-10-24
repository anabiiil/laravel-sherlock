<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Support\Null;

use Anabil\Sherlock\Contracts\TraceContext;
use Anabil\Sherlock\Data\HttpCallRecord;
use Anabil\Sherlock\Data\LogRecord;
use Anabil\Sherlock\Data\QueryRecord;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\RecordReason;
use DateTimeImmutable;

/**
 * Inactive context: every write is a no-op (zero overhead path).
 */
final class NullTraceContext implements TraceContext
{
    public function id(): string
    {
        return '';
    }

    public function type(): ?EntryType
    {
        return null;
    }

    public function isActive(): bool
    {
        return false;
    }

    public function isFull(): bool
    {
        return false;
    }

    public function elapsedMs(): float
    {
        return 0.0;
    }

    public function addQuery(QueryRecord $query): void {}

    public function addHttpCall(HttpCallRecord $call): void {}

    public function addLog(LogRecord $log): void {}

    public function cacheHit(): void {}

    public function cacheMiss(): void {}

    public function cacheWrite(): void {}

    public function addCacheTime(float $ms): void {}

    public function setName(string $name): void {}

    public function setRoute(?string $route): void {}

    public function setRequest(string $method, ?string $uri): void {}

    public function setStatusCode(?int $statusCode): void {}

    public function markFailed(): void {}

    public function setUserId(int|string|null $userId): void {}

    public function setBootstrapMs(float $ms): void {}

    public function addRecordReason(RecordReason $reason): void {}

    public function removeRecordReason(RecordReason $reason): void {}

    public function tag(string $key, string|int|float|bool|null $value): void {}

    public function meta(string $key, string|int|float|bool|null $value): void {}

    public function firstOccurrence(string $key): bool
    {
        return false;
    }

    public function secondOccurrence(string $key): bool
    {
        return false;
    }

    public function toTraceData(): TraceData
    {
        return new TraceData('', EntryType::Request, '', new DateTimeImmutable, 0.0);
    }
}
