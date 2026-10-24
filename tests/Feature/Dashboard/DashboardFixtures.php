<?php

declare(strict_types=1);

namespace Anabil\Tracing\Tests\Feature\Dashboard;

use Anabil\Tracing\Contracts\IssueRepository;
use Anabil\Tracing\Contracts\IssueService;
use Anabil\Tracing\Contracts\TraceRepository;
use Anabil\Tracing\Data\AiAnalysisResult;
use Anabil\Tracing\Data\Finding;
use Anabil\Tracing\Data\IssueRecord;
use Anabil\Tracing\Data\SourceLocation;
use Anabil\Tracing\Enums\Bottleneck;
use Anabil\Tracing\Enums\EntryType;
use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Enums\Severity;
use Anabil\Tracing\Http\Middleware\Authorize;
use Anabil\Tracing\Tests\Feature\Storage\StorageFixtures as F;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Seeds the real repositories (MySQL) for dashboard tests.
 */
final class DashboardFixtures
{
    /** The "web" group encrypts cookies: Testbench has no app key by default. */
    public static function web(): void
    {
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    public static function allowAccess(): void
    {
        self::web();
        Gate::define(Authorize::ABILITY, static fn (mixed $user = null): bool => true);
    }

    /**
     * One request trace with a slow query (+ EXPLAIN plan), an N+1 (6 x the same fingerprint),
     * an exception log and an HTTP call, run through the real IssueService.
     *
     * @return array{trace: string, slow: IssueRecord, nPlusOne: IssueRecord}
     */
    public static function seed(?CarbonImmutable $at = null, string $name = 'GET /users'): array
    {
        $at ??= CarbonImmutable::now()->subMinutes(5);

        $queries = [F::query(timeMs: 900.0, offsetMs: 5.0)];
        for ($i = 0; $i < 6; $i++) {
            $queries[] = F::query('select * from posts where user_id = ?', 2.0, [$i], 'fp-posts', 910.0 + $i, new SourceLocation('app/Http/Controllers/UserController.php', 50));
        }

        $trace = F::traceData($queries, name: $name, durationMs: 1250.0, startedAt: $at, logs: [F::exception()], httpCalls: [F::httpCall()]);
        $nPlusOne = new Finding(
            IssueType::NPlusOne, Severity::Critical, 'N+1 queries on posts', '6 queries from one location', 'issue-n-plus-one',
            ['count' => 6, 'total_ms' => 12.0], new SourceLocation('app/Http/Controllers/UserController.php', 50),
            'select * from posts where user_id = ?', 'fp-posts', 'Eager load posts with ->with(\'posts\').',
        );

        $recorded = F::recorded($trace, [F::finding(), $nPlusOne], explainPlans: ['fp-users' => F::plan()]);
        $traceId = app(TraceRepository::class)->store($recorded);
        app(IssueService::class)->recordFindings($traceId, $recorded);

        return [
            'trace' => $traceId,
            'slow' => self::issue('issue-slow-users'),
            'nPlusOne' => self::issue('issue-n-plus-one'),
        ];
    }

    /**
     * A bare trace (for list filters / pagination).
     */
    public static function trace(
        string $name = 'GET /plain',
        EntryType $type = EntryType::Request,
        float $durationMs = 100.0,
        Bottleneck $bottleneck = Bottleneck::Database,
        ?CarbonImmutable $at = null,
    ): string {
        $data = F::traceData([F::query()], $type, $name, $durationMs, $at ?? CarbonImmutable::now()->subMinutes(10));

        return app(TraceRepository::class)->store(F::recorded($data, bottleneck: $bottleneck));
    }

    /**
     * An issue with a hand-made context (title, SQL, etc. exactly as given).
     *
     * @param  array<string, mixed>  $context
     */
    public static function issueWith(
        string $fingerprint,
        string $title = 'Plain issue',
        IssueType $type = IssueType::SlowQuery,
        Severity $severity = Severity::Warning,
        array $context = [],
        ?CarbonImmutable $at = null,
    ): IssueRecord {
        $finding = new Finding($type, $severity, $title, 'description', $fingerprint);
        app(IssueRepository::class)->recordOccurrence($finding, $context, $at ?? CarbonImmutable::now()->subMinutes(3));

        return self::issue($fingerprint);
    }

    public static function analyze(IssueRecord $issue, string $markdown): void
    {
        app(IssueRepository::class)->saveAiAnalysis($issue->id, AiAnalysisResult::done($markdown, 'test-model'));
    }

    public static function issue(string $fingerprint): IssueRecord
    {
        return app(IssueRepository::class)->findByFingerprint($fingerprint)
            ?? throw new RuntimeException("Issue [{$fingerprint}] was not seeded.");
    }
}
