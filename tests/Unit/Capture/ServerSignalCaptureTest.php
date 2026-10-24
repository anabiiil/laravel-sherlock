<?php

declare(strict_types=1);

use Anabil\Sherlock\Context\ContextManager;
use Anabil\Sherlock\Context\TraceContext;
use Anabil\Sherlock\Contracts\PersistenceStrategy;
use Anabil\Sherlock\Contracts\ServerProbe;
use Anabil\Sherlock\Contracts\TraceAnalyzer;
use Anabil\Sherlock\Data\AnalysisResult;
use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Data\MemoryLeakSignal;
use Anabil\Sherlock\Data\RecordedTrace;
use Anabil\Sherlock\Data\RecordingDecision;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Data\Verdict;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\RecordReason;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Pipeline\DefaultTraceRecorder;

function fakeProbe(float $load = 2.0, ?int $cores = 4): ServerProbe
{
    return new class($load, $cores) implements ServerProbe
    {
        public float $cpu = 1000.0;

        /** @var array<string, int> */
        public array $calls = ['cpu' => 0, 'load' => 0, 'cores' => 0, 'memory' => 0];

        public function __construct(private readonly float $load, private readonly ?int $cores) {}

        public function cpuTimeMs(): ?float
        {
            $this->calls['cpu']++;

            return $this->cpu;
        }

        public function loadAverage(): ?float
        {
            $this->calls['load']++;

            return $this->load;
        }

        public function cpuCores(): ?int
        {
            $this->calls['cores']++;

            return $this->cores;
        }

        public function memoryAvailableMb(): ?int
        {
            $this->calls['memory']++;

            return 1024;
        }
    };
}

/**
 * @param  list<Finding>  $findings
 * @return array{0: DefaultTraceRecorder, 1: object, 2: object}
 */
function signalRecorder(ServerProbe $probe, array $findings = []): array
{
    $analyzer = new class($findings) implements TraceAnalyzer
    {
        /** @var list<TraceData> */
        public array $seen = [];

        public function __construct(private readonly array $findings) {}

        public function analyze(TraceData $trace): AnalysisResult
        {
            $this->seen[] = $trace;

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

    return [new DefaultTraceRecorder($analyzer, $persistence, Severity::Warning, 'auto', null, $probe, 1.5), $analyzer, $persistence];
}

it('measures the CPU time of a unit between start and freeze, and the load at the end', function (): void {
    $probe = fakeProbe();
    $manager = new ContextManager(probe: $probe, release: 'abc1234');

    $manager->start(EntryType::Job, 'App\\Jobs\\A', RecordingDecision::light());
    $probe->cpu = 1250.0;
    $manager->active()?->freeze();
    $probe->cpu = 9999.0;
    $trace = $manager->stop();

    expect($trace?->cpuMs)->toBe(250.0)
        ->and($trace?->loadAvg)->toBe(2.0)
        ->and($trace?->release)->toBe('abc1234')
        ->and($trace?->cpuCores)->toBeNull()
        ->and($probe->calls)->toBe(['cpu' => 2, 'load' => 1, 'cores' => 0, 'memory' => 0]);
});

it('measures nothing for orphan contexts and without a probe', function (): void {
    $probe = fakeProbe();
    $manager = new ContextManager(probe: $probe);
    $orphan = $manager->orphan(EntryType::Command, 'artisan')->toTraceData();

    $plain = (new TraceContext('01J', EntryType::Request, 'GET /', microtime(true), false))->toTraceData();

    expect($orphan->cpuMs)->toBeNull()
        ->and($orphan->loadAvg)->toBeNull()
        ->and($probe->calls['cpu'])->toBe(0)
        ->and($plain->cpuMs)->toBeNull()
        ->and($plain->release)->toBeNull();
});

it('keeps queue wait, memory leak and capped lazy loads on the context', function (): void {
    $context = new TraceContext('01J', EntryType::Job, 'App\\Jobs\\A', microtime(true), false);
    $context->setQueueWaitMs(-5.0);
    $context->setMemoryLeak(new MemoryLeakSignal(1, 2, 3, 1, 1.0));
    $resolved = 0;
    for ($i = 0; $i < TraceContext::MAX_LAZY_LOADS + 20; $i++) {
        $context->addLazyLoad('App\\Models\\M'.$i, 'rel', function () use (&$resolved): string {
            $resolved++;

            return 'table';
        });
        $context->addLazyLoad('App\\Models\\M'.$i, 'rel', function () use (&$resolved): string {
            $resolved++;

            return 'table';
        });
    }
    $context->addLazyLoad('App\\Models\\Broken', 'rel', fn (): string => throw new RuntimeException('no'));
    $context->freeze();
    $context->addLazyLoad('App\\Models\\M0', 'rel');

    $trace = $context->toTraceData();

    expect($trace->queueWaitMs)->toBe(0.0)
        ->and($trace->memoryLeak?->jobs)->toBe(3)
        ->and($trace->lazyLoads)->toHaveCount(TraceContext::MAX_LAZY_LOADS)
        ->and($trace->lazyLoads[0]->count)->toBe(2)
        ->and($trace->lazyLoads[0]->table)->toBe('table')
        ->and($resolved)->toBe(TraceContext::MAX_LAZY_LOADS);
});

it('resolves cores before analysis only when the load could cross the threshold', function (): void {
    $low = fakeProbe();
    [$recorder, $analyzer, $persistence] = signalRecorder($low);
    $recorder->record(new TraceData('01J', EntryType::Request, 'GET /', new DateTimeImmutable, 10.0, loadAvg: 1.2));

    $high = fakeProbe();
    [$recorderHigh, $analyzerHigh] = signalRecorder($high);
    $recorderHigh->record(new TraceData('01J', EntryType::Request, 'GET /', new DateTimeImmutable, 10.0, loadAvg: 6.0));

    expect($analyzer->seen[0]->cpuCores)->toBeNull()
        ->and($low->calls['cores'])->toBe(0)
        ->and($low->calls['memory'])->toBe(0)
        ->and($persistence->persisted)->toBe([])
        ->and($analyzerHigh->seen[0]->cpuCores)->toBe(4)
        ->and($analyzerHigh->seen[0]->loadPerCore())->toBe(1.5);
});

it('adds cores and available memory to persisted traces only', function (): void {
    $probe = fakeProbe();
    [$recorder, , $persistence] = signalRecorder($probe, [new Finding(IssueType::SlowQuery, Severity::Warning, 't', '', 'f')]);

    $recorder->record(new TraceData('01J', EntryType::Request, 'GET /', new DateTimeImmutable, 10.0, recordReasons: [RecordReason::Sample], loadAvg: 1.0));

    expect($persistence->persisted)->toHaveCount(1)
        ->and($persistence->persisted[0]->trace->cpuCores)->toBe(4)
        ->and($persistence->persisted[0]->trace->memoryFreeMb)->toBe(1024)
        ->and($probe->calls['memory'])->toBe(1);
});
