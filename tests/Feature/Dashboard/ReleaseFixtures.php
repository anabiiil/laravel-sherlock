<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Tests\Feature\Dashboard;

use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Contracts\TraceRepository;
use Anabil\Sherlock\Data\AnalysisResult;
use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Data\LazyLoadRecord;
use Anabil\Sherlock\Data\MemoryLeakSignal;
use Anabil\Sherlock\Data\RecordedTrace;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Data\Verdict;
use Anabil\Sherlock\Enums\Bottleneck;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\IssueStatus;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\RecordReason;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Tests\Feature\Storage\StorageFixtures as F;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Two releases (v1 deployed 10 days ago, v2 yesterday) with traces and issues covering every
 * comparison status (slower, faster, new, gone, unchanged) and new / regressed / gone issues.
 */
final class ReleaseFixtures
{
    public const PREVIOUS = 'v1-def456';

    public const CURRENT = 'v2-abc123';

    public static function seed(): void
    {
        $prev = CarbonImmutable::now()->subDays(10);
        $curr = CarbonImmutable::now()->subDay();

        foreach (range(1, 3) as $i) {
            self::trace(self::PREVIOUS, 'GET /orders', 100.0, $prev);
            self::trace(self::CURRENT, 'GET /orders', 300.0, $curr);
            self::trace(self::PREVIOUS, 'GET /users', 400.0, $prev);
            self::trace(self::CURRENT, 'GET /users', 200.0, $curr);
            self::trace(self::PREVIOUS, 'App\\Jobs\\SyncStock', 50.0, $prev, EntryType::Job);
            self::trace(self::CURRENT, 'App\\Jobs\\SyncStock', 51.0, $curr, EntryType::Job);
        }
        self::trace(self::CURRENT, 'GET /brand-new', 80.0, $curr);
        self::trace(self::PREVIOUS, 'GET /removed', 80.0, $prev);

        $issues = app(IssueRepository::class);
        $issues->recordOccurrence(self::finding('rel-new', 'N+1 on posts since v2', 4200.0), [], $curr, null, null, self::CURRENT);
        $issues->recordOccurrence(self::finding('rel-gone', 'Old slow export', 300.0), [], $prev, null, null, self::PREVIOUS);

        $outcome = $issues->recordOccurrence(self::finding('rel-back', 'Came back after deploy', 900.0), [], $prev, null, null, self::PREVIOUS);
        $issues->updateStatus($outcome->issueId, IssueStatus::Resolved, $prev->addHour());
        $issues->recordOccurrence(self::finding('rel-back', 'Came back after deploy', 900.0), [], $curr->addMinutes(5), null, null, self::CURRENT);
    }

    public static function trace(
        string $release,
        string $name,
        float $durationMs,
        ?CarbonImmutable $at = null,
        EntryType $type = EntryType::Request,
        RecordReason $reason = RecordReason::Sample,
    ): string {
        $trace = new TraceData(
            id: (string) Str::ulid(),
            type: $type,
            name: $name,
            startedAt: $at ?? CarbonImmutable::now(),
            durationMs: $durationMs,
            method: $type === EntryType::Request ? 'GET' : null,
            queries: [F::query(timeMs: $durationMs / 4)],
            recordReasons: [$reason],
            release: $release,
        );

        return app(TraceRepository::class)->store(
            new RecordedTrace($trace, new AnalysisResult([], new Verdict(Bottleneck::Database, 'db')), $reason, false),
        );
    }

    /**
     * A job trace with every server signal, lazy loads and a worker memory leak.
     */
    public static function signalTrace(string $release = self::CURRENT): string
    {
        $trace = new TraceData(
            id: (string) Str::ulid(),
            type: EntryType::Job,
            name: 'App\\Jobs\\ImportFeed',
            startedAt: CarbonImmutable::now()->subMinutes(2),
            durationMs: 2000.0,
            queries: [F::query()],
            recordReasons: [RecordReason::Local],
            release: $release,
            cpuMs: 200.0,
            loadAvg: 7.92,
            cpuCores: 4,
            memoryFreeMb: 512,
            queueWaitMs: 45000.0,
            lazyLoads: [new LazyLoadRecord('App\\Models\\Feed', 'items', 12, 'feed_items')],
            memoryLeak: new MemoryLeakSignal(50 * 1_048_576, 90 * 1_048_576, 120, 1, 0.8, ['App\\Jobs\\ImportFeed' => 30 * 1_048_576], 32 * 1_048_576),
        );

        $verdict = new Verdict(Bottleneck::ServerLoad, 'Server load 7.92 on 4 cores (198%) while this job ran.', 2000.0, [], [], [
            'cpu_ms' => 200.0, 'load_avg' => 7.92, 'cpu_cores' => 4, 'load_per_core' => 1.98, 'queue_wait_ms' => 45000.0,
        ]);

        return app(TraceRepository::class)->store(
            new RecordedTrace($trace, new AnalysisResult([], $verdict), RecordReason::Local, false),
        );
    }

    public static function finding(string $fingerprint, string $title, float $impactMs, IssueType $type = IssueType::NPlusOne): Finding
    {
        return new Finding($type, Severity::Warning, $title, 'description', $fingerprint, impactMs: $impactMs);
    }
}
