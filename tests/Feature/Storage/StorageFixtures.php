<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Tests\Feature\Storage;

use Anabil\Sherlock\Data\AnalysisResult;
use Anabil\Sherlock\Data\ExplainInsight;
use Anabil\Sherlock\Data\ExplainPlan;
use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Data\HttpCallRecord;
use Anabil\Sherlock\Data\LogRecord;
use Anabil\Sherlock\Data\QueryRecord;
use Anabil\Sherlock\Data\RecordedTrace;
use Anabil\Sherlock\Data\SourceLocation;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Data\Verdict;
use Anabil\Sherlock\Enums\Bottleneck;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\ExplainInsightType;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\RecordReason;
use Anabil\Sherlock\Enums\Severity;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Str;

/**
 * Builders for storage tests.
 */
final class StorageFixtures
{
    public static function query(
        string $sql = 'select * from users where id = ?',
        float $timeMs = 1.5,
        ?array $bindings = [1],
        ?string $fingerprint = 'fp-users',
        float $offsetMs = 0.0,
        ?SourceLocation $location = null,
    ): QueryRecord {
        $query = new QueryRecord($sql, $bindings, $timeMs, 'mysql', 'mysql', $location ?? new SourceLocation('app/Http/Controllers/UserController.php', 42), $offsetMs);

        return $fingerprint === null ? $query : $query->withNormalization($sql, $fingerprint);
    }

    /**
     * @param  list<QueryRecord>  $queries
     */
    public static function traceData(
        array $queries = [],
        EntryType $type = EntryType::Request,
        string $name = 'GET /users',
        float $durationMs = 250.0,
        ?DateTimeImmutable $startedAt = null,
        array $logs = [],
        array $httpCalls = [],
        ?string $id = null,
    ): TraceData {
        return new TraceData(
            id: $id ?? (string) Str::ulid(),
            type: $type,
            name: $name,
            startedAt: $startedAt ?? CarbonImmutable::now(),
            durationMs: $durationMs,
            method: $type === EntryType::Request ? 'GET' : null,
            uri: $type === EntryType::Request ? '/users?page=2' : null,
            route: $type === EntryType::Request ? 'users.index' : null,
            statusCode: $type === EntryType::Request ? 200 : null,
            bootstrapMs: 12.5,
            memoryPeakBytes: 8_388_608,
            queries: $queries,
            httpCalls: $httpCalls,
            cacheHits: 3,
            cacheMisses: 1,
            logs: $logs,
            userId: 15,
            recordReasons: [RecordReason::Local],
            full: true,
            tags: ['tenant' => 'acme'],
            metadata: ['action' => 'UserController@index'],
        );
    }

    public static function finding(
        string $fingerprint = 'issue-slow-users',
        Severity $severity = Severity::Warning,
        IssueType $type = IssueType::SlowQuery,
        string $title = 'Slow query on users',
        ?string $queryFingerprint = 'fp-users',
    ): Finding {
        return new Finding(
            $type,
            $severity,
            $title,
            'Query took 912ms',
            $fingerprint,
            ['time_ms' => 912.4, 'count' => 1],
            new SourceLocation('app/Http/Controllers/UserController.php', 42),
            $queryFingerprint === null ? null : 'select * from users where id = ?',
            $queryFingerprint,
            'Add an index on users.email',
        );
    }

    /**
     * @param  list<Finding>  $findings
     */
    public static function recorded(
        ?TraceData $trace = null,
        array $findings = [],
        bool $storeBindings = true,
        RecordReason $reason = RecordReason::Local,
        Bottleneck $bottleneck = Bottleneck::Database,
        array $explainPlans = [],
    ): RecordedTrace {
        $trace ??= self::traceData([self::query()]);

        return new RecordedTrace(
            $trace,
            new AnalysisResult(
                $findings,
                new Verdict($bottleneck, '72% of 250ms spent in 1 query', 250.0, ['db' => 180.0], ['db' => 72.0]),
                $explainPlans,
            ),
            $reason,
            $storeBindings,
        );
    }

    public static function plan(): ExplainPlan
    {
        return new ExplainPlan('mysql', 'mysql', 'select * from users where id = ?', ['query_block' => ['select_id' => 1]], [
            new ExplainInsight(ExplainInsightType::FullTableScan, Severity::Warning, 'Full table scan on `users`', 'users', 'users(email)', 120000),
        ]);
    }

    public static function exception(string $message = 'Something failed'): LogRecord
    {
        return new LogRecord('error', $message, 'RuntimeException', 'app/Services/Billing.php', 10, [
            ['file' => 'app/Services/Billing.php', 'line' => 10, 'class' => 'App\\Services\\Billing', 'function' => 'charge'],
        ], ['order' => 15]);
    }

    public static function httpCall(): HttpCallRecord
    {
        return new HttpCallRecord('POST', 'https://api.example.com/charge?key=[REDACTED]', 200, 1400.0, 20.0);
    }
}
