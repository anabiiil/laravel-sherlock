<?php

declare(strict_types=1);

namespace Anabil\Tracing\Tests\Feature\Notifications;

use Anabil\Tracing\Contracts\IssueRepository;
use Anabil\Tracing\Data\Finding;
use Anabil\Tracing\Data\IssueOutcome;
use Anabil\Tracing\Data\IssueRecord;
use Anabil\Tracing\Data\SourceLocation;
use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Enums\Severity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Config + issue builders for notification tests (real tracing_issues table).
 */
final class NotificationFixtures
{
    public const SLACK_URL = 'https://hooks.slack.test/services/T000/B000/secretwebhook';

    public const TELEGRAM_TOKEN = '123456:telegram-secret-token';

    /**
     * @param  array<string, mixed>  $overrides  merged into tracing.notifications
     */
    public static function configure(array $overrides = []): void
    {
        $config = array_replace_recursive([
            'enabled' => true,
            'channels' => ['slack', 'telegram', 'mail'],
            'min_severity' => 'warning',
            'throttle_minutes' => 60,
            'notify_on' => ['new' => true, 'regressed' => true, 'exceptions' => true],
            'dashboard_url' => null,
            'timeout' => 5,
            'slack' => ['webhook_url' => self::SLACK_URL, 'min_severity' => 'warning', 'username' => 'Tracing', 'channel' => null],
            'telegram' => ['bot_token' => self::TELEGRAM_TOKEN, 'chat_id' => '-100200300', 'min_severity' => 'warning', 'api_url' => 'https://api.telegram.org'],
            'mail' => ['to' => ['ops@example.test'], 'min_severity' => 'warning', 'mailer' => null],
        ], $overrides);

        // Lists replace, never merge index by index.
        if (array_key_exists('channels', $overrides)) {
            $config['channels'] = $overrides['channels'];
        }
        if (isset($overrides['mail']) && is_array($overrides['mail']) && array_key_exists('to', $overrides['mail'])) {
            $config['mail']['to'] = $overrides['mail']['to'];
        }

        config()->set('tracing.notifications', $config);
    }

    public static function finding(
        string $fingerprint = 'notif-issue',
        Severity $severity = Severity::Critical,
        IssueType $type = IssueType::SlowQuery,
    ): Finding {
        return new Finding(
            $type,
            $severity,
            'Slow query on orders',
            'Query took 912ms',
            $fingerprint,
            ['time_ms' => 912.4],
            new SourceLocation('app/Http/Controllers/OrderController.php', 42),
            $type->isQueryRelated() ? 'select * from `orders` where `user_id` = ?' : null,
            $type->isQueryRelated() ? 'q-fp' : null,
        );
    }

    public static function record(
        string $fingerprint = 'notif-issue',
        Severity $severity = Severity::Critical,
        IssueType $type = IssueType::SlowQuery,
        ?CarbonImmutable $at = null,
    ): IssueOutcome {
        $finding = self::finding($fingerprint, $severity, $type);

        return self::issues()->recordOccurrence($finding, $finding->toArray() + [
            'entry' => ['type' => 'request', 'name' => 'GET /orders', 'method' => 'GET', 'route' => 'orders.index'],
            'trace' => ['duration_ms' => 2412.0, 'db_ms' => 1700.0, 'db_count' => 38],
            'verdict' => ['reason' => '72% of 2.4s spent in 38 queries'],
        ], $at ?? CarbonImmutable::now());
    }

    public static function issues(): IssueRepository
    {
        return app(IssueRepository::class);
    }

    public static function find(int|string $id): IssueRecord
    {
        $issue = self::issues()->find($id);
        assert($issue instanceof IssueRecord);

        return $issue;
    }

    /** Successful responses for both HTTP channels. */
    public static function fakeHttp(int $slackStatus = 200, int $telegramStatus = 200): void
    {
        Http::fake([
            'hooks.slack.test/*' => Http::response('ok', $slackStatus),
            'api.telegram.org/*' => Http::response(
                $telegramStatus === 200 ? ['ok' => true, 'result' => []] : ['ok' => false, 'description' => 'Bad Request: chat not found'],
                $telegramStatus,
            ),
        ]);
    }
}
