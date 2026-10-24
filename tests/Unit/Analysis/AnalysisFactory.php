<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Tests\Unit\Analysis;

use Anabil\Sherlock\Analysis\DefaultBottleneckClassifier;
use Anabil\Sherlock\Analysis\TraceAnalysisPipeline;
use Anabil\Sherlock\Data\HttpCallRecord;
use Anabil\Sherlock\Data\LogRecord;
use Anabil\Sherlock\Data\QueryRecord;
use Anabil\Sherlock\Data\SourceLocation;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Support\HashFingerprinter;
use Anabil\Sherlock\Support\SqlQueryNormalizer;
use DateTimeImmutable;

/**
 * Hand-built traces for analysis tests.
 */
final class AnalysisFactory
{
    public static function fingerprinter(): HashFingerprinter
    {
        return new HashFingerprinter(new SqlQueryNormalizer);
    }

    /**
     * @param  list<QueryRecord>  $queries
     * @param  list<HttpCallRecord>  $httpCalls
     * @param  list<LogRecord>  $logs
     */
    public static function trace(
        array $queries = [],
        float $durationMs = 100.0,
        EntryType $type = EntryType::Request,
        string $name = '/orders',
        ?string $method = 'GET',
        array $httpCalls = [],
        array $logs = [],
        int $memoryPeakBytes = 0,
        int $cacheHits = 0,
        int $cacheMisses = 0,
        float $cacheMs = 0.0,
        float $bootstrapMs = 0.0,
        bool $full = false,
        ?int $totalQueryCount = null,
    ): TraceData {
        return new TraceData(
            id: '01JTEST',
            type: $type,
            name: $name,
            startedAt: new DateTimeImmutable('2026-01-01 00:00:00'),
            durationMs: $durationMs,
            method: $type === EntryType::Request ? $method : null,
            bootstrapMs: $bootstrapMs,
            memoryPeakBytes: $memoryPeakBytes,
            queries: $queries,
            httpCalls: $httpCalls,
            cacheHits: $cacheHits,
            cacheMisses: $cacheMisses,
            cacheMs: $cacheMs,
            logs: $logs,
            full: $full,
            totalQueryCount: $totalQueryCount,
        );
    }

    /**
     * @param  array<int|string, scalar|null>|null  $bindings
     */
    public static function query(
        string $sql,
        float $timeMs = 1.0,
        ?array $bindings = null,
        ?SourceLocation $location = null,
        string $connection = 'mysql',
        string $driver = 'mysql',
    ): QueryRecord {
        return new QueryRecord($sql, $bindings, $timeMs, $connection, $driver, $location);
    }

    public static function location(
        int $line = 42,
        string $file = 'app/Http/Controllers/OrderController.php',
        ?string $class = 'App\\Http\\Controllers\\OrderController',
        ?string $function = 'index',
    ): SourceLocation {
        return new SourceLocation($file, $line, $class, $function);
    }

    /**
     * Normalize + fingerprint queries like the pipeline does before analyzers run.
     */
    public static function enrich(TraceData $trace): TraceData
    {
        return (new TraceAnalysisPipeline(new SqlQueryNormalizer, self::fingerprinter(), [], new DefaultBottleneckClassifier))->enrich($trace);
    }

    /**
     * @return list<QueryRecord>
     */
    public static function repeat(QueryRecord $query, int $times): array
    {
        return array_fill(0, $times, $query);
    }

    /**
     * @return array{sql: string, plan: array<array-key, mixed>}
     */
    public static function fixture(string $name): array
    {
        /** @var array{sql: string, plan: array<array-key, mixed>} $data */
        $data = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/explain/'.$name.'.json'), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }
}
