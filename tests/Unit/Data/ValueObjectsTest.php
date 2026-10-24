<?php

declare(strict_types=1);

use Anabil\Tracing\Data\AiAnalysisResult;
use Anabil\Tracing\Data\AnalysisResult;
use Anabil\Tracing\Data\ExplainInsight;
use Anabil\Tracing\Data\ExplainPlan;
use Anabil\Tracing\Data\Finding;
use Anabil\Tracing\Data\HttpCallRecord;
use Anabil\Tracing\Data\LogRecord;
use Anabil\Tracing\Data\QueryRecord;
use Anabil\Tracing\Data\RecordingDecision;
use Anabil\Tracing\Data\SourceLocation;
use Anabil\Tracing\Data\TraceData;
use Anabil\Tracing\Data\Verdict;
use Anabil\Tracing\Data\WatchState;
use Anabil\Tracing\Enums\AiStatus;
use Anabil\Tracing\Enums\EntryType;
use Anabil\Tracing\Enums\ExplainInsightType;
use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Enums\RecordReason;
use Anabil\Tracing\Enums\Severity;

function makeTrace(array $overrides = []): TraceData
{
    return new TraceData(...array_merge([
        'id' => '01HZX',
        'type' => EntryType::Request,
        'name' => 'GET /orders',
        'startedAt' => new DateTimeImmutable('2024-01-01 00:00:00'),
        'durationMs' => 500.0,
    ], $overrides));
}

it('computes trace aggregates', function (): void {
    $trace = makeTrace([
        'queries' => [
            new QueryRecord('select 1', null, 10.0, 'mysql', 'mysql'),
            new QueryRecord('select 2', null, 15.5, 'mysql', 'mysql'),
        ],
        'httpCalls' => [new HttpCallRecord('GET', 'https://api.example.com/x?y=1', 200, 120.0)],
        'cacheHits' => 3,
        'cacheMisses' => 1,
        'memoryPeakBytes' => 64 * 1048576,
        'logs' => [new LogRecord('error', 'boom', RuntimeException::class), new LogRecord('error', 'plain')],
    ]);

    expect($trace->dbMs)->toBe(25.5)
        ->and($trace->queryCount())->toBe(2)
        ->and($trace->httpMs)->toBe(120.0)
        ->and($trace->httpCount())->toBe(1)
        ->and($trace->cacheLookups())->toBe(4)
        ->and($trace->cacheMissRatio())->toBe(0.25)
        ->and($trace->memoryPeakMb())->toBe(64.0)
        ->and($trace->hasException())->toBeTrue()
        ->and($trace->exceptions())->toHaveCount(1)
        ->and($trace->httpCalls[0]->host())->toBe('api.example.com');
});

it('honours totals when capture caps dropped records', function (): void {
    $trace = makeTrace(['queries' => [new QueryRecord('select 1', null, 1.0, 'mysql', 'mysql')], 'totalQueryCount' => 900, 'totalDbMs' => 450.0]);

    expect($trace->queryCount())->toBe(900)->and($trace->dbMs)->toBe(450.0);
});

it('returns modified copies immutably', function (): void {
    $trace = makeTrace(['recordReasons' => [RecordReason::Sample], 'tags' => ['a' => 1]]);
    $query = (new QueryRecord('select * from t where id = 1', [1], 5.0, 'mysql', 'mysql'))
        ->withNormalization('select * from t where id = ?', 'fp');

    $copy = $trace->withQueries([$query])->withRecordReason(RecordReason::Threshold);

    expect($trace->queries)->toBe([])
        ->and($copy->queries)->toBe([$query])
        ->and($copy->recordReasons)->toBe([RecordReason::Sample, RecordReason::Threshold])
        ->and($copy->tags)->toBe(['a' => 1])
        ->and($copy->withRecordReason(RecordReason::Sample))->toBe($copy)
        ->and($query->isNormalized())->toBeTrue()
        ->and($query->withoutBindings()->bindings)->toBeNull()
        ->and($query->isSelect())->toBeTrue()
        ->and((new QueryRecord('update t set a = 1', null, 1, 'm', 'mysql'))->isSelect())->toBeFalse()
        ->and($trace->toArray())->toHaveKeys(['id', 'type', 'db_ms', 'queries', 'record_reasons']);
});

it('builds query identity keys for duplicate detection', function (): void {
    $a = new QueryRecord('select * from t where id = ?', [1], 1, 'm', 'mysql');
    $b = new QueryRecord('select * from t where id = ?', [1], 2, 'm', 'mysql');
    $c = new QueryRecord('select * from t where id = ?', [2], 2, 'm', 'mysql');

    expect($a->identityKey())->toBe($b->identityKey())->not->toBe($c->identityKey());
});

it('combines recording decisions', function (): void {
    $skip = RecordingDecision::skip();
    $light = RecordingDecision::light(RecordReason::Sample);
    $full = RecordingDecision::full(RecordReason::Header);

    $merged = $skip->merge($light)->merge($full);

    expect($skip->capture)->toBeFalse()
        ->and(RecordingDecision::light()->forcesPersistence())->toBeFalse()
        ->and($light->forcesPersistence())->toBeTrue()
        ->and($merged->capture)->toBeTrue()
        ->and($merged->full)->toBeTrue()
        ->and($merged->reasons)->toBe([RecordReason::Sample, RecordReason::Header]);
});

it('summarizes analysis results', function (): void {
    $warning = new Finding(IssueType::SlowQuery, Severity::Warning, 'Slow', '', 'fp1');
    $info = new Finding(IssueType::CacheMissRate, Severity::Info, 'Cache', '', 'fp2');
    $error = new Finding(IssueType::Exception, Severity::Info, 'Boom', '', 'fp3');

    expect(AnalysisResult::empty()->hasFindings())->toBeFalse()
        ->and(AnalysisResult::empty()->highestSeverity())->toBeNull()
        ->and((new AnalysisResult([$info, $warning], Verdict::unknown()))->highestSeverity())->toBe(Severity::Warning)
        ->and((new AnalysisResult([$info], Verdict::unknown()))->crosses(Severity::Warning))->toBeFalse()
        ->and((new AnalysisResult([$info, $warning], Verdict::unknown()))->crosses(Severity::Warning))->toBeTrue()
        ->and((new AnalysisResult([$error], Verdict::unknown()))->crosses(Severity::Critical))->toBeTrue()
        ->and((new AnalysisResult([$error], Verdict::unknown()))->hasErrors())->toBeTrue();
});

it('round-trips findings', function (): void {
    $finding = new Finding(
        IssueType::NPlusOne, Severity::Critical, 'N+1 on orders', 'desc', 'fp',
        ['count' => 38, 'time_ms' => 120.5], new SourceLocation('app/A.php', 3, 'A', 'b'),
        'select * from orders where user_id = ?', 'qfp', 'Use eager loading',
    );

    expect(Finding::fromArray($finding->toArray()))->toEqual($finding)
        ->and($finding->withSeverity(Severity::Info)->severity)->toBe(Severity::Info)
        ->and($finding->withRecommendation(null)->recommendation)->toBeNull();
});

it('describes verdicts and explain plans', function (): void {
    $verdict = new Verdict(Anabil\Tracing\Enums\Bottleneck::Database, '72% in db', 1000.0, ['db' => 720.0], ['db' => 72.0]);
    $plan = new ExplainPlan('mysql', 'mysql', 'select ...', [], [
        new ExplainInsight(ExplainInsightType::FullTableScan, Severity::Warning, 'scan'),
        new ExplainInsight(ExplainInsightType::Filesort, Severity::Critical, 'sort'),
    ]);

    expect($verdict->ms(Verdict::SEGMENT_DB))->toBe(720.0)
        ->and($verdict->percentage(Verdict::SEGMENT_CACHE))->toBe(0.0)
        ->and($verdict->toArray()['bottleneck'])->toBe('database')
        ->and(Verdict::SEGMENTS)->toHaveCount(5)
        ->and($plan->hasProblems())->toBeTrue()
        ->and($plan->worstSeverity())->toBe(Severity::Critical)
        ->and($plan->toArray()['insights'])->toHaveCount(2);
});

it('handles log levels', function (): void {
    $log = new LogRecord('critical', 'x', null, 'app/A.php', 4);

    expect($log->isAtLeast('error'))->toBeTrue()
        ->and($log->isAtLeast('emergency'))->toBeFalse()
        ->and(LogRecord::levelWeight('unknown'))->toBe(0)
        ->and($log->location()?->toString())->toBe('app/A.php:4')
        ->and($log->isException())->toBeFalse();
});

it('serializes watch state', function (): void {
    $now = new DateTimeImmutable('@1700000000');
    $state = new WatchState($now, $now->modify('+30 minutes'), 'api/orders*', 15);
    $restored = WatchState::fromArray($state->toArray());

    expect($restored->routePattern)->toBe('api/orders*')
        ->and($restored->userId)->toBe(15)
        ->and($restored->expiresAt->getTimestamp())->toBe(1700001800)
        ->and($state->isActive($now))->toBeTrue()
        ->and($state->isActive($now->modify('+31 minutes')))->toBeFalse()
        ->and($state->remainingSeconds($now))->toBe(1800);
});

it('creates AI analysis results', function (): void {
    expect(AiAnalysisResult::done('Root cause...', 'claude')->isDone())->toBeTrue()
        ->and(AiAnalysisResult::failed('timeout')->status)->toBe(AiStatus::Failed)
        ->and(AiAnalysisResult::skipped(AiStatus::SkippedBudget)->status)->toBe(AiStatus::SkippedBudget);
});
