<?php

declare(strict_types=1);

use Anabil\Tracing\Contracts\PersistenceStrategy;
use Anabil\Tracing\Contracts\TraceAnalyzer;
use Anabil\Tracing\Data\AnalysisResult;
use Anabil\Tracing\Data\Finding;
use Anabil\Tracing\Data\QueryRecord;
use Anabil\Tracing\Data\RecordedTrace;
use Anabil\Tracing\Data\TraceData;
use Anabil\Tracing\Data\Verdict;
use Anabil\Tracing\Enums\EntryType;
use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Enums\RecordReason;
use Anabil\Tracing\Enums\Severity;
use Anabil\Tracing\Pipeline\DefaultTraceRecorder;

function recorderWith(array $findings, ?Throwable $throw = null, string $bindings = 'auto'): array
{
    $analyzer = new class($findings, $throw) implements TraceAnalyzer
    {
        public function __construct(private array $findings, private ?Throwable $throw) {}

        public function analyze(TraceData $trace): AnalysisResult
        {
            if ($this->throw) {
                throw $this->throw;
            }

            return new AnalysisResult($this->findings, Verdict::unknown(), [], $trace);
        }
    };

    $persistence = new class implements PersistenceStrategy
    {
        /** @var list<RecordedTrace> */
        public array $persisted = [];

        public function persist(RecordedTrace $trace): void
        {
            $this->persisted[] = $trace;
        }
    };

    return [new DefaultTraceRecorder($analyzer, $persistence, Severity::Warning, $bindings), $persistence];
}

function trace(array $reasons = [], bool $full = false): TraceData
{
    return new TraceData('01J', EntryType::Request, 'GET /', new DateTimeImmutable, 10.0, recordReasons: $reasons, full: $full);
}

it('drops traces without forcing reasons or crossed thresholds', function (): void {
    [$recorder, $persistence] = recorderWith([new Finding(IssueType::CacheMissRate, Severity::Info, 't', '', 'f')]);

    $recorder->record(trace());

    expect($persistence->persisted)->toBe([]);
});

it('persists on a crossed threshold', function (): void {
    [$recorder, $persistence] = recorderWith([new Finding(IssueType::SlowQuery, Severity::Warning, 't', '', 'f')]);

    $recorder->record(trace());

    expect($persistence->persisted)->toHaveCount(1)
        ->and($persistence->persisted[0]->reason)->toBe(RecordReason::Threshold)
        ->and($persistence->persisted[0]->trace->recordReasons)->toBe([RecordReason::Threshold])
        ->and($persistence->persisted[0]->storeBindings)->toBeFalse();
});

it('persists errors regardless of severity', function (): void {
    [$recorder, $persistence] = recorderWith([new Finding(IssueType::Exception, Severity::Info, 't', '', 'f')]);

    $recorder->record(trace());

    expect($persistence->persisted)->toHaveCount(1);
});

it('persists forced reasons and prefers threshold over sample', function (): void {
    [$recorder, $persistence] = recorderWith([]);
    $recorder->record(trace([RecordReason::Sample]));
    $recorder->record(trace([RecordReason::Header], true));

    [$recorder2, $persistence2] = recorderWith([new Finding(IssueType::SlowQuery, Severity::Critical, 't', '', 'f')]);
    $recorder2->record(trace([RecordReason::Sample]));

    expect($persistence->persisted[0]->reason)->toBe(RecordReason::Sample)
        ->and($persistence->persisted[1]->reason)->toBe(RecordReason::Header)
        ->and($persistence->persisted[1]->storeBindings)->toBeTrue()
        ->and($persistence2->persisted[0]->reason)->toBe(RecordReason::Threshold);
});

it('resolves store_bindings', function (): void {
    [$always, $p1] = recorderWith([], null, 'always');
    [$never, $p2] = recorderWith([], null, 'never');
    $always->record(trace([RecordReason::Sample]));
    $never->record(trace([RecordReason::Local], true));

    expect($p1->persisted[0]->storeBindings)->toBeTrue()
        ->and($p2->persisted[0]->storeBindings)->toBeFalse();
});

it('never throws into the application', function (): void {
    [$recorder, $persistence] = recorderWith([], new RuntimeException('analysis failed'));

    $recorder->record(trace([RecordReason::Local]));

    expect($persistence->persisted)->toBe([]);
});

it('never hands persistence more bindings than may be stored', function (): void {
    $queries = [
        new QueryRecord('select * from t where id = ?', [5], 1.0, 'mysql', 'mysql'),
        new QueryRecord('select * from t where ref = ?', null, 500.0, 'mysql', 'mysql', explainBindings: ['secret-ref']),
    ];
    $make = static fn (bool $full): TraceData => new TraceData('01J', EntryType::Request, 'GET /', new DateTimeImmutable, 10.0, queries: $queries, recordReasons: [RecordReason::Sample], full: $full);

    [$auto, $p1] = recorderWith([]);
    [$never, $p2] = recorderWith([], null, 'never');
    $auto->record($make(true));
    $never->record($make(true));
    $auto->record($make(false));

    // Full + auto: stored bindings kept, EXPLAIN-only bindings dropped.
    expect($p1->persisted[0]->trace->queries[0]->bindings)->toBe([5])
        ->and($p1->persisted[0]->trace->queries[1]->explainBindings)->toBeNull()
        ->and($p1->persisted[0]->analysis->trace?->queries[1]->explainBindings)->toBeNull();

    // store_bindings=never, and lightweight traces: nothing at all, in the trace or the analysis.
    foreach ([$p2->persisted[0], $p1->persisted[1]] as $recorded) {
        foreach ([...$recorded->trace->queries, ...($recorded->analysis->trace->queries ?? [])] as $query) {
            expect($query->bindings)->toBeNull()->and($query->explainBindings)->toBeNull();
        }
        expect(serialize($recorded))->not->toContain('secret-ref');
    }
});
