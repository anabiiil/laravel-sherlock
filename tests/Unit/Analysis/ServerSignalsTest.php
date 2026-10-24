<?php

declare(strict_types=1);

use Anabil\Sherlock\Analysis\Analyzers\MemoryLeakAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\QueueWaitAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\ServerLoadAnalyzer;
use Anabil\Sherlock\Analysis\DefaultBottleneckClassifier;
use Anabil\Sherlock\Data\MemoryLeakSignal;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Data\Verdict;
use Anabil\Sherlock\Enums\Bottleneck;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Tests\Unit\Analysis\AnalysisFactory as F;

/**
 * @param  array<string, mixed>  $overrides  TraceData constructor arguments
 */
function signalTrace(array $overrides = []): TraceData
{
    return new TraceData(...array_merge([
        'id' => '01JSIGNAL',
        'type' => EntryType::Request,
        'name' => '/orders',
        'startedAt' => new DateTimeImmutable('2026-01-01 00:00:00'),
        'durationMs' => 2100.0,
        'method' => 'GET',
    ], $overrides));
}

it('classifies a slow request on an overloaded server as server load', function (): void {
    $verdict = (new DefaultBottleneckClassifier)->classify(signalTrace([
        'cpuMs' => 40.0,
        'loadAvg' => 7.9,
        'cpuCores' => 4,
    ]));

    expect($verdict->bottleneck)->toBe(Bottleneck::ServerLoad)
        ->and($verdict->reason)->toBe('Server load 7.9 on 4 cores (198%) while this request ran; CPU time only 40ms of 2.10s: the process was mostly waiting for a CPU.')
        ->and($verdict->signals)->toBe(['cpu_ms' => 40.0, 'load_avg' => 7.9, 'cpu_cores' => 4, 'load_per_core' => 1.975])
        ->and($verdict->toArray()['signals']['load_per_core'])->toBe(1.975);
});

it('keeps the database verdict when the database dominates, even on a loaded server', function (): void {
    $verdict = (new DefaultBottleneckClassifier)->classify(signalTrace([
        'queries' => [F::query('select * from `orders`', 1800.0)],
        'cpuMs' => 40.0,
        'loadAvg' => 7.9,
        'cpuCores' => 4,
    ]));

    expect($verdict->bottleneck)->toBe(Bottleneck::Database);
});

it('does not blame the server for CPU-bound code on a busy server', function (): void {
    $verdict = (new DefaultBottleneckClassifier)->classify(signalTrace([
        'cpuMs' => 1900.0,
        'loadAvg' => 7.9,
        'cpuCores' => 4,
    ]));

    expect($verdict->bottleneck)->toBe(Bottleneck::Application)
        ->and($verdict->reason)->toStartWith('CPU-bound application code: 1.90s of CPU time in a 2.10s application segment');
});

it('classifies wall time without CPU, database or HTTP as waiting / blocked', function (): void {
    $verdict = (new DefaultBottleneckClassifier)->classify(signalTrace([
        'cpuMs' => 80.0,
        'loadAvg' => 0.5,
        'cpuCores' => 4,
    ]));

    expect($verdict->bottleneck)->toBe(Bottleneck::Waiting)
        ->and($verdict->reason)->toContain('CPU time was only 80ms')
        ->and($verdict->reason)->toContain('waiting or blocked');
});

it('keeps the plain application verdict between the CPU ratios and without signals', function (): void {
    $between = (new DefaultBottleneckClassifier)->classify(signalTrace(['cpuMs' => 1000.0]));
    $none = (new DefaultBottleneckClassifier)->classify(signalTrace());

    expect($between->bottleneck)->toBe(Bottleneck::Application)
        ->and($between->reason)->toContain('CPU time 1.00s')
        ->and($between->signals)->toBe(['cpu_ms' => 1000.0])
        ->and($none->bottleneck)->toBe(Bottleneck::Application)
        ->and($none->signals)->toBe([])
        ->and($none->toArray())->not->toHaveKey('signals');
});

it('needs the core count to judge the load', function (): void {
    $verdict = (new DefaultBottleneckClassifier)->classify(signalTrace(['loadAvg' => 50.0, 'cpuMs' => 40.0]));

    expect($verdict->bottleneck)->toBe(Bottleneck::Waiting);
});

it('respects configured server thresholds', function (): void {
    $classifier = new DefaultBottleneckClassifier(['server_load' => 3.0, 'waiting' => 0.01]);

    expect($classifier->classify(signalTrace(['cpuMs' => 40.0, 'loadAvg' => 7.9, 'cpuCores' => 4]))->bottleneck)->toBe(Bottleneck::Application);
});

it('classifies a job that waited longer than it ran as queue backlog, even when it is short', function (): void {
    $verdict = (new DefaultBottleneckClassifier)->classify(signalTrace([
        'type' => EntryType::Job,
        'name' => 'App\\Jobs\\SendEmail',
        'method' => null,
        'durationMs' => 12.0,
        'queueWaitMs' => 45000.0,
        'metadata' => ['queue' => 'emails'],
    ]));

    expect($verdict->bottleneck)->toBe(Bottleneck::QueueBacklog)
        ->and($verdict->reason)->toBe('The job waited 45.00s in the queue `emails` before a worker started it, longer than it ran (12ms): workers are not keeping up.')
        ->and($verdict->breakdownMs[Verdict::SEGMENT_APPLICATION])->toBe(12.0)
        ->and($verdict->signals['queue_wait_ms'])->toBe(45000.0);
});

it('does not call a long job with a short wait a backlog', function (): void {
    $classifier = new DefaultBottleneckClassifier;

    expect($classifier->classify(signalTrace(['type' => EntryType::Job, 'queueWaitMs' => 45000.0, 'durationMs' => 60000.0]))->bottleneck)
        ->not->toBe(Bottleneck::QueueBacklog)
        ->and($classifier->classify(signalTrace(['type' => EntryType::Job, 'queueWaitMs' => 1000.0, 'durationMs' => 10.0]))->bottleneck)
        ->toBe(Bottleneck::Unknown);
});

it('raises a server load finding on slow entries only', function (): void {
    $analyzer = new ServerLoadAnalyzer(F::fingerprinter());

    $findings = $analyzer->analyze(signalTrace(['cpuMs' => 40.0, 'loadAvg' => 7.9, 'cpuCores' => 4]));
    $critical = $analyzer->analyze(signalTrace(['cpuMs' => 40.0, 'loadAvg' => 13.0, 'cpuCores' => 4]));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->type)->toBe(IssueType::ServerLoad)
        ->and($findings[0]->severity)->toBe(Severity::Warning)
        ->and($findings[0]->title)->toBe('Server overloaded: load 7.9 on 4 cores (198%) during GET /orders (2.10s)')
        ->and($findings[0]->metrics['load_per_core'])->toBe(1.975)
        ->and($findings[0]->impactMs)->toBe(2060.0)
        ->and($critical[0]->severity)->toBe(Severity::Critical)
        // One issue per server, whatever the entry.
        ->and($critical[0]->fingerprint)->toBe($findings[0]->fingerprint)
        ->and($analyzer->analyze(signalTrace(['durationMs' => 300.0, 'loadAvg' => 7.9, 'cpuCores' => 4])))->toBe([])
        ->and($analyzer->analyze(signalTrace(['loadAvg' => 3.9, 'cpuCores' => 4])))->toBe([])
        ->and($analyzer->analyze(signalTrace(['loadAvg' => 7.9])))->toBe([]);
});

it('raises a queue wait finding per queue', function (): void {
    $analyzer = new QueueWaitAnalyzer(F::fingerprinter(), 30000, 300000);
    $job = fn (float $wait, string $queue = 'emails'): TraceData => signalTrace([
        'type' => EntryType::Job,
        'name' => 'App\\Jobs\\SendEmail',
        'method' => null,
        'durationMs' => 120.0,
        'queueWaitMs' => $wait,
        'metadata' => ['queue' => $queue, 'connection' => 'redis'],
    ]);

    $findings = $analyzer->analyze($job(45000.0));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->type)->toBe(IssueType::QueueWait)
        ->and($findings[0]->title)->toBe("Job waited 45.00s in queue `emails` before starting — workers can't keep up")
        ->and($findings[0]->severity)->toBe(Severity::Warning)
        ->and($findings[0]->impactMs)->toBe(45000.0)
        ->and($findings[0]->recommendation)->toContain('Horizon')
        ->and($analyzer->analyze($job(400000.0))[0]->severity)->toBe(Severity::Critical)
        ->and($analyzer->analyze($job(400000.0))[0]->fingerprint)->toBe($findings[0]->fingerprint)
        ->and($analyzer->analyze($job(45000.0, 'default'))[0]->fingerprint)->not->toBe($findings[0]->fingerprint)
        ->and($analyzer->analyze($job(1000.0)))->toBe([])
        ->and($analyzer->analyze(signalTrace(['queueWaitMs' => 99999.0])))->toBe([]);
});

it('turns a memory leak signal into a finding naming the contributing jobs', function (): void {
    $signal = new MemoryLeakSignal(
        baselineBytes: 40 * 1048576,
        currentBytes: 75 * 1048576,
        jobs: 30,
        step: 1,
        growingRatio: 0.9,
        contributors: ['App\\Jobs\\ImportRow' => 30 * 1048576, 'App\\Jobs\\SendEmail' => 512000],
        thresholdBytes: 32 * 1048576,
    );
    $trace = signalTrace(['type' => EntryType::Job, 'name' => 'App\\Jobs\\ImportRow', 'method' => null, 'memoryLeak' => $signal, 'metadata' => ['queue' => 'imports']]);

    $findings = (new MemoryLeakAnalyzer(F::fingerprinter(), 128))->analyze($trace);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->type)->toBe(IssueType::MemoryLeak)
        ->and($findings[0]->severity)->toBe(Severity::Warning)
        ->and($findings[0]->title)->toBe('Memory leak in queue worker `imports`: +35MB over 30 jobs')
        ->and($findings[0]->description)->toContain('App\\Jobs\\ImportRow (+30MB)')
        ->and($findings[0]->metrics['top_job'])->toBe('App\\Jobs\\ImportRow')
        ->and($findings[0]->recommendation)->toContain('--max-jobs')
        ->and((new MemoryLeakAnalyzer(F::fingerprinter(), 32))->analyze($trace)[0]->severity)->toBe(Severity::Critical)
        ->and((new MemoryLeakAnalyzer(F::fingerprinter()))->analyze(signalTrace()))->toBe([]);
});

it('exposes the load per core and copies server signals', function (): void {
    $trace = signalTrace(['loadAvg' => 3.0]);

    expect($trace->loadPerCore())->toBeNull()
        ->and($trace->withServerSignals(2)->loadPerCore())->toBe(1.5)
        ->and($trace->withServerSignals(2, 512)->memoryFreeMb)->toBe(512)
        ->and($trace->withServerSignals(null, 512)->cpuCores)->toBeNull()
        ->and($trace->withServerSignals(2)->toArray())->toMatchArray(['load_avg' => 3.0, 'cpu_cores' => 2]);
});
