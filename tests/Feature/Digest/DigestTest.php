<?php

declare(strict_types=1);

use Anabil\Tracing\Contracts\AiAnalyzer;
use Anabil\Tracing\Contracts\AiProvider;
use Anabil\Tracing\Contracts\DigestService;
use Anabil\Tracing\Contracts\IssueRepository;
use Anabil\Tracing\Contracts\NotificationDispatcher;
use Anabil\Tracing\Contracts\TraceRepository;
use Anabil\Tracing\Data\AiAnalysisResult;
use Anabil\Tracing\Digest\DefaultDigestService;
use Anabil\Tracing\Digest\DigestBuilder;
use Anabil\Tracing\Enums\Bottleneck;
use Anabil\Tracing\Enums\EntryType;
use Anabil\Tracing\Enums\IssueStatus;
use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Enums\Severity;
use Anabil\Tracing\Tests\Feature\Digest\FakeNotificationDispatcher;
use Anabil\Tracing\Tests\Feature\Digest\SpyAiAnalyzer;
use Anabil\Tracing\Tests\Feature\Digest\SpyAiProvider;
use Anabil\Tracing\Tests\Feature\Storage\StorageFixtures as F;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

const DIGEST_AI_ANALYSIS = "## Root cause\nThe users lookup scans the whole table.\n\n## Fix\nAdd an index on `users.email` so the lookup stops scanning.\n\n## Code example\n```php\n\$table->index('email');\n```\n\n## Expected impact\n~90% faster.";

/** Fresh digest services after config / binding changes (they are singletons). */
function digestService(): DefaultDigestService
{
    app()->forgetInstance(DigestBuilder::class);
    app()->forgetInstance(DigestService::class);

    $service = app(DigestService::class);
    assert($service instanceof DefaultDigestService);

    return $service;
}

function digestDispatcher(): FakeNotificationDispatcher
{
    $fake = new FakeNotificationDispatcher;
    app()->instance(NotificationDispatcher::class, $fake);

    return $fake;
}

/** Seeds traces and issues around "now" (2026-10-20 12:00). */
function seedDigestData(): void
{
    $now = CarbonImmutable::now();
    $traces = app(TraceRepository::class);
    $issues = app(IssueRepository::class);

    $users = static fn (float $ms): array => [F::query('select * from users where email = ?', $ms, ['[REDACTED]'], 'fp-users')];

    $traces->store(F::recorded(F::traceData($users(300.0), name: 'GET /users', durationMs: 450.0, startedAt: $now->subHours(2))));
    $traces->store(F::recorded(F::traceData($users(100.0), name: 'GET /users', durationMs: 250.0, startedAt: $now->subHours(3))));
    $traces->store(F::recorded(
        F::traceData([F::query('select * from orders where status = ?', 900.0, ['paid'], 'fp-orders')], EntryType::Job, 'App\\Jobs\\SyncOrders', 1200.0, $now->subHour()),
        bottleneck: Bottleneck::Application,
    ));
    $traces->store(F::recorded(F::traceData([F::query('select * from legacy', 5000.0, [], 'fp-legacy')], name: 'GET /old', durationMs: 6000.0, startedAt: $now->subDays(10))));

    $issues->recordOccurrence(F::finding('i-new', Severity::Critical, IssueType::NPlusOne, 'N+1 queries on orders'), [], $now->subHour());

    // Real issues carry the finding (incl. its source location) in their context.
    $slow = F::finding('i-frequent', Severity::Warning, IssueType::SlowQuery, 'Slow query on users');
    $issues->recordOccurrence($slow, $slow->toArray(), $now->subDays(5));
    for ($i = 5; $i >= 1; $i--) {
        $issues->recordOccurrence($slow, $slow->toArray(), $now->subHours($i));
    }
    $frequent = $issues->findByFingerprint('i-frequent');
    $issues->saveAiAnalysis($frequent->id, AiAnalysisResult::done(DIGEST_AI_ANALYSIS, 'claude-test', $now->subDays(5)));

    $issues->recordOccurrence(F::finding('i-regressed', Severity::Warning, IssueType::HighMemory, 'High memory in reports', null), [], $now->subDays(30));
    $issues->updateStatus($issues->findByFingerprint('i-regressed')->id, IssueStatus::Resolved, $now->subDays(20));
    $issues->recordOccurrence(F::finding('i-regressed', Severity::Warning, IssueType::HighMemory, 'High memory in reports', null), [], $now->subHours(2));

    // Ignored on purpose: never part of a digest.
    $issues->recordOccurrence(F::finding('i-ignored', Severity::Critical, IssueType::DuplicateQuery, 'Ignored duplicates'), [], $now->subMinutes(30));
    $issues->updateStatus($issues->findByFingerprint('i-ignored')->id, IssueStatus::Ignored);

    $issues->recordOccurrence(F::finding('i-old', Severity::Info, IssueType::CacheMissRate, 'Old cache misses', null), [], $now->subDays(40));
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00'));
    Cache::flush();
    config()->set('tracing.digest.enabled', true);
    config()->set('tracing.digest.every_days', 1);
    config()->set('tracing.digest.channels', ['mail']);
    config()->set('tracing.digest.limit', 10);
});

it('builds the digest from stored traces and issues', function (): void {
    seedDigestData();
    digestService();
    $builder = app(DigestBuilder::class);
    $now = CarbonImmutable::now();

    $report = $builder->build($now->subDay(), $now);

    expect($report->traceCount)->toBe(3)
        ->and($report->openBySeverity)->toMatchArray(['critical' => 1, 'warning' => 2, 'info' => 1])
        ->and(array_map(fn ($i) => $i->fingerprint, $report->newIssues))->toBe(['i-new'])
        ->and(array_map(fn ($i) => $i->fingerprint, $report->regressedIssues))->toBe(['i-regressed'])
        ->and($report->frequentIssues[0]->fingerprint)->toBe('i-frequent')
        ->and(array_map(fn ($i) => $i->fingerprint, $report->frequentIssues))->not->toContain('i-old')
        ->and($report->slowestEntries[0]->name)->toBe('App\\Jobs\\SyncOrders')
        ->and($report->topQueries[0]->normalizedSql)->toBe('select * from orders where status = ?')
        ->and(array_map(fn ($q) => $q->fingerprint, $report->topQueries))->not->toContain('fp-legacy')
        ->and($report->bottlenecks)->toBe(['database' => 2, 'application' => 1])
        ->and($report->severity())->toBe(Severity::Critical);

    $message = $builder->message($report);

    expect($message->title)->toContain('Tracing digest')
        ->and($message->severity)->toBe(Severity::Critical)
        ->and($message->fingerprint)->toBe(DigestBuilder::FINGERPRINT)
        ->and($message->issueId)->toBeNull()
        ->and($message->summary)->toContain('3 traces recorded, 1 new and 1 regressed issues; 4 open issues')
        ->and($message->fields['Open issues'])->toBe('4 (1 critical, 2 warning, 1 info)')
        ->and($message->fields['New issues'])->toContain('[CRITICAL] N+1 queries on orders')
        ->and($message->fields['Regressed issues'])->toContain('High memory in reports')
        ->and(implode("\n", $message->fields))->not->toContain('Ignored duplicates')
        ->and($message->fields['Most frequent issues'])->toContain('Slow query on users (Slow query · 6x · app/Http/Controllers/UserController.php:42)')
        ->and($message->fields['Most frequent issues'])->toContain('AI: Add an index on users.email so the lookup stops scanning.')
        ->and($message->fields['Most frequent issues'])->not->toContain('$table->index')
        ->and($message->fields['Slowest entries'])->toContain('GET /users (request): avg 350 ms · max 450 ms · 2x')
        ->and($message->fields['Top queries'])->toContain('select * from users where email = ?')
        ->and($message->fields['Top queries'])->not->toContain('[REDACTED]')
        ->and($message->fields['Bottlenecks'])->toBe('Database 67% (2) · Application / Server 33% (1)')
        ->and($message->url)->toEndWith('/tracing');
});

it('respects digest.limit for every section', function (): void {
    seedDigestData();
    config()->set('tracing.digest.limit', 1);
    digestService();
    $now = CarbonImmutable::now();

    $report = app(DigestBuilder::class)->build($now->subDays(60), $now);

    expect($report->newIssues)->toHaveCount(1)
        ->and($report->frequentIssues)->toHaveCount(1)
        ->and($report->slowestEntries)->toHaveCount(1)
        ->and($report->topQueries)->toHaveCount(1);
});

it('says when everything is quiet', function (): void {
    digestService();
    $now = CarbonImmutable::now();

    $message = app(DigestBuilder::class)->message(app(DigestBuilder::class)->build($now->subDay(), $now));

    expect($message->severity)->toBe(Severity::Info)
        ->and($message->summary)->toContain('All quiet')
        ->and($message->fields)->toHaveKeys(['Period', 'Traces recorded', 'Open issues'])
        ->and($message->fields)->not->toHaveKey('New issues');
});

it('excerpts the Fix section of a stored analysis', function (): void {
    expect(DigestBuilder::excerpt(DIGEST_AI_ANALYSIS))->toBe('Add an index on users.email so the lookup stops scanning.')
        ->and(DigestBuilder::excerpt('**Root cause**: plain text answer without sections'))->toBe('Root cause: plain text answer without sections')
        ->and(mb_strlen(DigestBuilder::excerpt(str_repeat('word ', 200))))->toBeLessThanOrEqual(160);
});

it('is never due while disabled', function (): void {
    config()->set('tracing.digest.enabled', false);
    $digest = digestService();

    expect($digest->isDue())->toBeFalse()
        ->and($digest->isDue(CarbonImmutable::now()->addYear()))->toBeFalse()
        ->and($digest->send())->toBeFalse();
});

it('is due right away when it was never sent', function (): void {
    expect(digestService()->isDue())->toBeTrue();
});

it('is due again after every_days calendar days', function (int $everyDays, array $expectations): void {
    config()->set('tracing.digest.every_days', $everyDays);
    $dispatcher = digestDispatcher();
    $digest = digestService();

    // Sent by the 09:00 check a few seconds late (queued job).
    $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:05'));
    expect($digest->send())->toBeTrue();

    foreach ($expectations as $day => $due) {
        $at = CarbonImmutable::parse('2026-10-20 09:00:00')->addDays($day);
        expect($digest->isDue($at))->toBe($due, "day +{$day}");
    }

    expect($dispatcher->sent)->toHaveCount(1);
})->with([
    'every day' => [1, [0 => false, 1 => true, 2 => true]],
    'every 2 days' => [2, [1 => false, 2 => true, 3 => true]],
    'every 7 days' => [7, [1 => false, 6 => false, 7 => true, 8 => true]],
]);

it('sends to the configured channels and stores the last-sent time', function (): void {
    config()->set('tracing.digest.channels', ['slack', 'telegram']);
    $dispatcher = digestDispatcher();
    $digest = digestService();

    expect($digest->send())->toBeTrue()
        ->and($dispatcher->sent)->toHaveCount(1)
        ->and($dispatcher->sent[0]['channels'])->toBe(['slack', 'telegram'])
        ->and($dispatcher->last()->fingerprint)->toBe(DigestBuilder::FINGERPRINT)
        ->and(Cache::get(DefaultDigestService::LAST_SENT_KEY))->toBe(CarbonImmutable::now()->getTimestamp())
        ->and($digest->lastSentAt()?->getTimestamp())->toBe(CarbonImmutable::now()->getTimestamp())
        ->and($digest->isDue())->toBeFalse();

    // Not due: nothing more is sent.
    expect($digest->send())->toBeFalse()
        ->and($dispatcher->sent)->toHaveCount(1);
});

it('sends to every enabled channel when digest.channels is empty', function (): void {
    config()->set('tracing.digest.channels', []);
    $dispatcher = digestDispatcher();

    digestService()->send();

    expect($dispatcher->sent[0]['channels'])->toBeNull();
});

it('force-sends even when disabled or not due', function (): void {
    config()->set('tracing.digest.enabled', false);
    $dispatcher = digestDispatcher();
    $digest = digestService();

    expect($digest->send(force: true))->toBeTrue()
        ->and($digest->send(force: true))->toBeTrue()
        ->and($dispatcher->sent)->toHaveCount(2)
        ->and(Cache::has(DefaultDigestService::LAST_SENT_KEY))->toBeTrue();
});

it('covers the period since the last digest', function (): void {
    $dispatcher = digestDispatcher();
    $digest = digestService();

    // First digest: the last every_days.
    $digest->send();
    expect($dispatcher->last()->fields['Period'])->toStartWith('2026-10-19 12:00 to 2026-10-20 12:00');

    // Three days later: everything since the previous digest.
    $this->travelTo(CarbonImmutable::parse('2026-10-23 09:00:00'));
    $digest->send();
    expect($dispatcher->last()->fields['Period'])->toStartWith('2026-10-20 12:00 to 2026-10-23 09:00');
});

it('skips the send while another process holds the lock', function (): void {
    $dispatcher = digestDispatcher();
    $digest = digestService();
    $lock = Cache::lock(DefaultDigestService::LOCK_KEY, 60);
    $lock->get();

    expect($digest->send(force: true))->toBeFalse()
        ->and($dispatcher->sent)->toBe([]);

    $lock->release();
    expect($digest->send(force: true))->toBeTrue();
});

it('never calls the AI, even for issues without an analysis', function (): void {
    seedDigestData();
    $analyzer = new SpyAiAnalyzer;
    $provider = new SpyAiProvider;
    app()->instance(AiAnalyzer::class, $analyzer);
    app()->instance(AiProvider::class, $provider);
    $dispatcher = digestDispatcher();

    expect(digestService()->send(force: true))->toBeTrue()
        ->and($analyzer->calls)->toBe(0)
        ->and($provider->calls)->toBe(0)
        ->and($dispatcher->last()->fields['Most frequent issues'])->toContain('AI: Add an index');

    // Issues without an AI analysis stay without one.
    expect(app(IssueRepository::class)->findByFingerprint('i-new')->aiAnalysis)->toBeNull();
});
