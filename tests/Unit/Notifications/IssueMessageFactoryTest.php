<?php

declare(strict_types=1);

use Anabil\Tracing\Data\IssueRecord;
use Anabil\Tracing\Enums\AiStatus;
use Anabil\Tracing\Enums\IssueStatus;
use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Enums\Severity;
use Anabil\Tracing\Notifications\IssueMessageFactory;
use Anabil\Tracing\Notifications\NotificationPolicy;
use Illuminate\Config\Repository;

function notifFactory(array $config = []): IssueMessageFactory
{
    $repository = new Repository(array_replace_recursive([
        'app' => ['name' => 'Shop', 'env' => 'production'],
        'tracing' => ['notifications' => ['throttle_minutes' => 60], 'dashboard' => ['enabled' => true]],
    ], $config));

    return new IssueMessageFactory(new NotificationPolicy($repository), $repository);
}

function notifIssue(array $overrides = []): IssueRecord
{
    return new IssueRecord(...array_merge([
        'id' => 12,
        'fingerprint' => 'issue-fp',
        'type' => IssueType::SlowQuery,
        'severity' => Severity::Critical,
        'status' => IssueStatus::Open,
        'title' => 'Slow query on orders',
        'context' => [
            'description' => 'Query took 912ms',
            'metrics' => ['time_ms' => 912.4, 'count' => 1, 'ignored' => null],
            'location' => ['file' => 'app/Http/Controllers/OrderController.php', 'line' => 42, 'class' => 'App\\Http\\Controllers\\OrderController', 'function' => 'index'],
            'sql' => 'select * from `orders` where `user_id` = ?',
            'entry' => ['type' => 'request', 'name' => 'GET /orders', 'method' => 'GET', 'route' => 'orders.index'],
            'trace' => ['duration_ms' => 2412.0, 'db_ms' => 1700.5, 'db_count' => 38],
            'verdict' => ['reason' => '72% of 2.4s spent in 38 queries'],
            'explain' => ['insights' => [['message' => 'Full table scan on `orders`', 'suggested_index' => 'orders(user_id)']]],
        ],
        'occurrences' => 5,
        'firstSeenAt' => new DateTimeImmutable('2026-10-20 10:00:00'),
        'lastSeenAt' => new DateTimeImmutable('2026-10-20 11:00:00'),
    ], $overrides));
}

it('builds a new-issue message from the issue context', function (): void {
    $message = notifFactory()->forIssue(notifIssue());

    expect($message->title)->toBe('Slow query on orders')
        ->and($message->severity)->toBe(Severity::Critical)
        ->and($message->summary)->toBe('Query took 912ms')
        ->and($message->fields)->toBe([
            'Type' => 'Slow query',
            'Severity' => 'Critical',
            'Entry' => 'GET orders.index',
            'Location' => 'app/Http/Controllers/OrderController.php:42 (App\\Http\\Controllers\\OrderController::index)',
            'Verdict' => '72% of 2.4s spent in 38 queries',
            'Duration' => '2.41 s',
            'Queries' => '38 (1.7 s)',
            'Time' => '912.4 ms',
            'Count' => '1',
            'EXPLAIN' => 'Full table scan on `orders` (suggested index: orders(user_id))',
            'Occurrences' => '5',
            'First seen' => '2026-10-20 10:00 UTC',
        ])
        ->and($message->sql)->toBe('select * from `orders` where `user_id` = ?')
        ->and($message->aiRecommendation)->toBeNull()
        ->and($message->url)->toBeNull()
        ->and($message->occurrences)->toBe(5)
        ->and($message->footer)->toBe('Tracing · Shop (production)')
        ->and($message->issueId)->toBe(12)
        ->and($message->fingerprint)->toBe('issue-fp');
});

it('includes the AI analysis only when it is done', function (): void {
    $done = notifFactory()->forIssue(notifIssue(['aiStatus' => AiStatus::Done, 'aiAnalysis' => "  Root cause: missing index.\n "]));
    $failed = notifFactory()->forIssue(notifIssue(['aiStatus' => AiStatus::Failed, 'aiAnalysis' => 'stale']));

    expect($done->aiRecommendation)->toBe('Root cause: missing index.')
        ->and($failed->aiRecommendation)->toBeNull();
});

it('builds the regression variant', function (): void {
    $message = notifFactory()->forIssue(notifIssue([
        'resolvedAt' => new DateTimeImmutable('2026-10-19 08:30:00'),
        'regressedAt' => new DateTimeImmutable('2026-10-20 10:00:00'),
    ]));

    expect($message->title)->toBe('Regression: Slow query on orders')
        ->and($message->summary)->toBe('This issue came back after being resolved on 2026-10-19 08:30 UTC. Query took 912ms');
});

it('builds the throttled summary variant', function (): void {
    $factory = notifFactory();

    expect($factory->summary(notifIssue(['occurrences' => 341]), 340, 60)->summary)->toBe('Occurred 340 more times in the last hour (341 in total).')
        ->and($factory->summary(notifIssue(), 1, 5)->summary)->toBe('Occurred 1 more time in the last 5 minutes (5 in total).')
        ->and($factory->summary(notifIssue(), 3, 120)->summary)->toContain('last 2 hours')
        ->and($factory->summary(notifIssue(), 3, 1440)->summary)->toContain('last 24 hours')
        ->and($factory->summary(notifIssue(), 3, 60)->title)->toBe('Still happening: Slow query on orders')
        ->and($factory->summary(notifIssue(), 3, 60)->occurrences)->toBe(3);
});

it('describes errors, jobs and sparse contexts', function (): void {
    $message = notifFactory()->forIssue(notifIssue([
        'type' => IssueType::Exception,
        'context' => [
            'entry' => ['type' => 'job', 'name' => 'App\\Jobs\\Charge'],
            'exception' => ['class' => 'RuntimeException', 'message' => 'Card declined'],
        ],
    ]));

    expect($message->summary)->toBe('Exception detected.')
        ->and($message->fields['Entry'])->toBe('job: App\\Jobs\\Charge')
        ->and($message->fields['Exception'])->toBe('RuntimeException: Card declined')
        ->and($message->fields)->not->toHaveKeys(['Location', 'Verdict', 'Duration', 'EXPLAIN'])
        ->and($message->sql)->toBeNull();
});

it('formats durations', function (): void {
    expect(IssueMessageFactory::ms(3.456))->toBe('3.46 ms')
        ->and(IssueMessageFactory::ms(912.44))->toBe('912.4 ms')
        ->and(IssueMessageFactory::ms(2412))->toBe('2.41 s');
});

it('provides a critical sample message', function (): void {
    $sample = notifFactory()->sample();

    expect($sample->severity)->toBe(Severity::Critical)
        ->and($sample->title)->toBe('Test notification from Tracing');
});
