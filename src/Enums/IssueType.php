<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Enums;

enum IssueType: string
{
    case SlowQuery = 'slow_query';
    case NPlusOne = 'n_plus_one';
    case DuplicateQuery = 'duplicate_query';
    case ExcessiveQueries = 'excessive_queries';
    case SlowRequest = 'slow_request';
    case SlowJob = 'slow_job';
    case SlowCommand = 'slow_command';
    case HighMemory = 'high_memory';
    case SlowExternalCall = 'slow_external_call';
    case CacheMissRate = 'cache_miss_rate';
    case MissingIndex = 'missing_index';
    case ServerLoad = 'server_load';
    case QueueWait = 'queue_wait';
    case MemoryLeak = 'memory_leak';
    case Exception = 'exception';
    case LogError = 'log_error';

    public function label(): string
    {
        return match ($this) {
            self::SlowQuery => 'Slow query',
            self::NPlusOne => 'N+1 queries',
            self::DuplicateQuery => 'Duplicate queries',
            self::ExcessiveQueries => 'Excessive queries',
            self::SlowRequest => 'Slow request',
            self::SlowJob => 'Slow job',
            self::SlowCommand => 'Slow command',
            self::HighMemory => 'High memory',
            self::SlowExternalCall => 'Slow external call',
            self::CacheMissRate => 'High cache miss rate',
            self::MissingIndex => 'Missing index',
            self::ServerLoad => 'Server load',
            self::QueueWait => 'Queue wait',
            self::MemoryLeak => 'Memory leak',
            self::Exception => 'Exception',
            self::LogError => 'Log error',
        };
    }

    /**
     * Errors (exceptions / error logs) as opposed to performance problems.
     */
    public function isError(): bool
    {
        return $this === self::Exception || $this === self::LogError;
    }

    public function isPerformance(): bool
    {
        return ! $this->isError();
    }

    /**
     * Issue types tied to a specific query fingerprint.
     */
    public function isQueryRelated(): bool
    {
        return match ($this) {
            self::SlowQuery, self::NPlusOne, self::DuplicateQuery, self::MissingIndex => true,
            default => false,
        };
    }
}
