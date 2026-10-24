<?php

declare(strict_types=1);

use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Enums\Severity;
use Anabil\Tracing\Notifications\NotificationPolicy;
use Illuminate\Config\Repository;

function notifPolicy(array $notifications = [], array $extra = []): NotificationPolicy
{
    return new NotificationPolicy(new Repository(array_replace_recursive(['tracing' => ['notifications' => $notifications]], $extra)));
}

it('reads the global switch and normalizes channel names', function (): void {
    expect(notifPolicy()->enabled())->toBeFalse()
        ->and(notifPolicy(['enabled' => true])->enabled())->toBeTrue()
        ->and(notifPolicy(['channels' => [' Slack', 'mail', 'slack', '', 3]])->channelNames())->toBe(['slack', 'mail'])
        ->and(notifPolicy(['channels' => 'telegram, mail'])->channelNames())->toBe(['telegram', 'mail'])
        ->and(notifPolicy(['channels' => null])->channelNames())->toBe([]);
});

it('lets channels raise but never lower the global minimum severity', function (): void {
    $policy = notifPolicy([
        'min_severity' => 'warning',
        'slack' => ['min_severity' => 'info'],
        'mail' => ['min_severity' => 'critical'],
    ]);

    expect($policy->channelMinSeverity('slack'))->toBe(Severity::Warning)
        ->and($policy->channelMinSeverity('mail'))->toBe(Severity::Critical)
        ->and($policy->channelMinSeverity('telegram'))->toBe(Severity::Warning)
        ->and($policy->channelAccepts('mail', Severity::Warning))->toBeFalse()
        ->and($policy->channelAccepts('slack', Severity::Warning))->toBeTrue()
        ->and($policy->channelAccepts('slack', Severity::Info))->toBeFalse();
});

it('applies notify_on toggles per issue kind', function (): void {
    $policy = notifPolicy(['notify_on' => ['new' => false, 'regressed' => true, 'exceptions' => true]]);

    expect($policy->allowsType(IssueType::SlowQuery))->toBeFalse()
        ->and($policy->allowsType(IssueType::SlowQuery, regressed: true))->toBeTrue()
        ->and($policy->allowsType(IssueType::Exception))->toBeTrue()
        ->and(notifPolicy(['notify_on' => ['exceptions' => false]])->allowsType(IssueType::LogError, true))->toBeFalse()
        ->and(notifPolicy()->allowsType(IssueType::NPlusOne))->toBeTrue();
});

it('bounds throttle and timeout values', function (): void {
    expect(notifPolicy(['throttle_minutes' => 0])->throttleMinutes())->toBe(1)
        ->and(notifPolicy()->throttleMinutes())->toBe(60)
        ->and(notifPolicy(['timeout' => 999])->timeout())->toBe(30)
        ->and(notifPolicy(['timeout' => 'x'])->timeout())->toBe(10)
        ->and(notifPolicy(['dashboard_url' => 'https://ops.test/'])->dashboardBaseUrl())->toBe('https://ops.test')
        ->and(notifPolicy()->dashboardBaseUrl())->toBeNull();
});

it('detects a sync package queue', function (): void {
    expect(notifPolicy([], ['queue' => ['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]]])->queueIsSync())->toBeTrue()
        ->and(notifPolicy([], ['queue' => ['default' => 'sync', 'connections' => ['redis' => ['driver' => 'redis']]], 'tracing' => ['queue' => ['connection' => 'redis']]])->queueIsSync())->toBeFalse()
        ->and(notifPolicy([], ['queue' => ['default' => 'database', 'connections' => ['database' => ['driver' => 'database']]]])->queueIsSync())->toBeFalse();
});
