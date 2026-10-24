<?php

declare(strict_types=1);

use Anabil\Tracing\Contracts\NotificationDispatcher;
use Anabil\Tracing\Data\NotificationMessage;
use Anabil\Tracing\Enums\IssueStatus;
use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Enums\Severity;
use Anabil\Tracing\Jobs\NotifyIssueJob;
use Anabil\Tracing\Notifications\Channels\MailChannel;
use Anabil\Tracing\Notifications\Channels\RedactingChannel;
use Anabil\Tracing\Notifications\Channels\SlackChannel;
use Anabil\Tracing\Notifications\Channels\TelegramChannel;
use Anabil\Tracing\Notifications\DefaultNotificationDispatcher;
use Anabil\Tracing\Notifications\Mail\IssueNotificationMail;
use Anabil\Tracing\Notifications\NotificationFailed;
use Anabil\Tracing\Tests\Feature\Notifications\NotificationFixtures as N;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

function notifDispatcher(): DefaultNotificationDispatcher
{
    return app(DefaultNotificationDispatcher::class);
}

function notifMessage(Severity $severity = Severity::Critical, array $overrides = []): NotificationMessage
{
    return new NotificationMessage(...array_merge([
        'title' => 'Slow query on orders',
        'severity' => $severity,
        'summary' => 'Query took 912ms',
        'fields' => ['Entry' => 'GET orders.index'],
        'sql' => 'select * from `orders` where `user_id` = ?',
    ], $overrides));
}

/** @return list<string> hosts that received a request */
function notifHosts(): array
{
    return array_values(array_map(static fn (array $pair): string => parse_url($pair[0]->url(), PHP_URL_HOST), Http::recorded()->all()));
}

beforeEach(function (): void {
    N::configure();
    Mail::fake();
});

it('replaces the null dispatcher and registers the three channels behind redacting decorators', function (): void {
    $all = notifDispatcher()->allChannels();

    expect(app(NotificationDispatcher::class))->toBe(notifDispatcher())
        ->and(array_keys($all))->toBe(['slack', 'telegram', 'mail'])
        ->and($all)->each->toBeInstanceOf(RedactingChannel::class)
        ->and($all['slack']->inner())->toBeInstanceOf(SlackChannel::class)
        ->and($all['telegram']->inner())->toBeInstanceOf(TelegramChannel::class)
        ->and($all['mail']->inner())->toBeInstanceOf(MailChannel::class);
});

it('lists only channels that are both enabled in config and configured', function (): void {
    N::configure(['channels' => ['slack', 'mail', 'unknown'], 'mail' => ['to' => ['not-an-email']]]);

    expect(array_keys(notifDispatcher()->channels()))->toBe(['slack']);

    N::configure(['channels' => ['telegram'], 'telegram' => ['chat_id' => null]]);

    expect(notifDispatcher()->channels())->toBe([]);
});

it('sends to every enabled channel', function (): void {
    N::fakeHttp();

    notifDispatcher()->send(notifMessage());

    Mail::assertSent(IssueNotificationMail::class, fn (IssueNotificationMail $mail): bool => $mail->hasTo('ops@example.test')
        && $mail->subjectLine === '[Tracing] Critical: Slow query on orders');

    expect(notifHosts())->toBe(['hooks.slack.test', 'api.telegram.org']);

    Http::assertSent(fn (Request $request): bool => $request->url() === N::SLACK_URL
        && $request['attachments'][0]['blocks'][0]['type'] === 'header'
        && $request['username'] === 'Tracing');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bot'.N::TELEGRAM_TOKEN.'/sendMessage'
        && $request['chat_id'] === '-100200300'
        && $request['parse_mode'] === 'HTML'
        && str_contains($request['text'], '<b>Slow query on orders</b>'));
});

it('does nothing when notifications are disabled', function (): void {
    N::configure(['enabled' => false]);
    N::fakeHttp();

    notifDispatcher()->send(notifMessage());

    Http::assertNothingSent();
    Mail::assertNothingSent();
});

it('filters channels by global and per-channel minimum severity', function (): void {
    N::configure([
        'min_severity' => 'warning',
        'slack' => ['min_severity' => 'info'],
        'telegram' => ['min_severity' => 'warning'],
        'mail' => ['min_severity' => 'critical'],
    ]);
    N::fakeHttp();

    notifDispatcher()->send(notifMessage(Severity::Info));
    expect(Http::recorded())->toHaveCount(0);

    notifDispatcher()->send(notifMessage(Severity::Warning));
    expect(notifHosts())->toBe(['hooks.slack.test', 'api.telegram.org']);
    Mail::assertNothingSent();

    notifDispatcher()->send(notifMessage(Severity::Critical));
    Mail::assertSent(IssueNotificationMail::class, 1);
});

it('restricts sending to the requested channels', function (): void {
    N::fakeHttp();

    notifDispatcher()->send(notifMessage(), ['Telegram']);

    expect(notifHosts())->toBe(['api.telegram.org']);
    Mail::assertNothingSent();
});

it('keeps sending when one channel fails and never throws', function (): void {
    N::fakeHttp(slackStatus: 500);
    Log::spy();

    $results = notifDispatcher()->deliver(notifMessage());
    notifDispatcher()->deliver(notifMessage());

    expect($results['slack'])->toBeInstanceOf(NotificationFailed::class)
        ->and($results['slack']->getMessage())->toBe('slack: HTTP 500 - ok')
        ->and($results['telegram'])->toBeNull()
        ->and($results['mail'])->toBeNull()
        ->and(notifHosts())->toBe(['hooks.slack.test', 'api.telegram.org', 'hooks.slack.test', 'api.telegram.org']);

    Mail::assertSent(IssueNotificationMail::class, 2);
    // Logged quietly, once per channel per window.
    Log::shouldHaveReceived('warning')->once();
});

it('reports telegram API errors and connection failures without leaking secrets', function (): void {
    N::configure(['channels' => ['telegram', 'slack']]);
    N::fakeHttp(telegramStatus: 400);

    $telegram = notifDispatcher()->deliver(notifMessage())['telegram'];

    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out for '.N::SLACK_URL.' and bot'.N::TELEGRAM_TOKEN));
    $failures = notifDispatcher()->deliver(notifMessage());

    expect($telegram?->getMessage())->toBe('telegram: HTTP 400 - Bad Request: chat not found')
        ->and($failures['slack']?->getMessage())->toContain('timed out')->not->toContain('secretwebhook')->not->toContain('hooks.slack.test')
        ->and($failures['telegram']?->getMessage())->not->toContain(N::TELEGRAM_TOKEN);
});

it('treats a telegram response with ok=false as a failure', function (): void {
    N::configure(['channels' => ['telegram']]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Forbidden'], 200)]);

    expect(notifDispatcher()->deliver(notifMessage())['telegram']?->getMessage())->toBe('telegram: HTTP 200 - Forbidden');
});

it('redacts emails, passwords and tokens from every outgoing payload', function (): void {
    N::fakeHttp();
    $secrets = ['john.doe@example.com', 'hunter2-secret', 'abcdef1234567890tokenvalue', 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0In0.c2lnbmF0dXJl'];

    notifDispatcher()->send(notifMessage(overrides: [
        'title' => 'Login failed for john.doe@example.com',
        'summary' => 'password=hunter2-secret was rejected',
        'fields' => ['api_token' => 'abcdef1234567890tokenvalue', 'Exception' => 'Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0In0.c2lnbmF0dXJl invalid'],
        'aiRecommendation' => 'Contact john.doe@example.com',
    ]));

    expect(Http::recorded())->toHaveCount(2);
    foreach (Http::recorded() as [$request]) {
        foreach ($secrets as $secret) {
            expect($request->body())->not->toContain($secret);
        }
        expect($request->body())->toContain('[REDACTED]');
    }

    Mail::assertSent(IssueNotificationMail::class, function (IssueNotificationMail $mail) use ($secrets): bool {
        $html = $mail->render();
        $all = $html.$mail->subjectLine.json_encode($mail->payload);
        foreach ($secrets as $secret) {
            expect($all)->not->toContain($secret);
        }

        return str_contains($html, '[REDACTED]');
    });
});

it('notifies a new issue once and marks it notified', function (): void {
    N::fakeHttp();
    $outcome = N::record();
    $this->travel(5)->seconds();

    (new NotifyIssueJob($outcome->issueId))->handle(app(NotificationDispatcher::class));
    notifDispatcher()->notifyIssue($outcome->issueId); // duplicate job

    $issue = N::find($outcome->issueId);

    expect(notifHosts())->toBe(['hooks.slack.test', 'api.telegram.org'])
        ->and($issue->lastNotifiedAt?->format('Y-m-d H:i:s'))->toBe(CarbonImmutable::now()->format('Y-m-d H:i:s'))
        ->and($issue->pendingNotifyCount)->toBe(0);

    Mail::assertSent(IssueNotificationMail::class, 1);
    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'Slow query on orders')
        && str_contains($request->body(), 'orders.index'));
});

it('marks the issue notified even when the only channel fails, so repeats are throttled', function (): void {
    N::configure(['channels' => ['slack']]);
    N::fakeHttp(slackStatus: 500);
    $outcome = N::record();

    notifDispatcher()->notifyIssue($outcome->issueId);

    expect(N::find($outcome->issueId)->lastNotifiedAt)->not->toBeNull();
});

it('skips ignored, resolved and missing issues', function (IssueStatus $status): void {
    N::fakeHttp();
    $outcome = N::record();
    N::issues()->updateStatus($outcome->issueId, $status);

    notifDispatcher()->notifyIssue($outcome->issueId);
    notifDispatcher()->notifyIssue(999999);

    Http::assertNothingSent();
    Mail::assertNothingSent();
    expect(N::find($outcome->issueId)->lastNotifiedAt)->toBeNull();
})->with([IssueStatus::Ignored, IssueStatus::Resolved]);

it('skips issues below every channel threshold without marking them', function (): void {
    N::configure(['min_severity' => 'critical']);
    N::fakeHttp();
    $outcome = N::record(severity: Severity::Warning);

    notifDispatcher()->notifyIssue($outcome->issueId);

    Http::assertNothingSent();
    expect(N::find($outcome->issueId)->lastNotifiedAt)->toBeNull();
});

it('honours notify_on toggles', function (): void {
    N::configure(['notify_on' => ['new' => false, 'exceptions' => true]]);
    N::fakeHttp();

    notifDispatcher()->notifyIssue(N::record('perf')->issueId);
    expect(Http::recorded())->toHaveCount(0);

    notifDispatcher()->notifyIssue(N::record('error', type: IssueType::Exception)->issueId);
    expect(Http::recorded())->toHaveCount(2);
});

it('sends the regression variant for a resolved issue that came back', function (): void {
    N::fakeHttp();
    $first = N::record(at: CarbonImmutable::now()->subDay());
    N::issues()->markNotified($first->issueId, CarbonImmutable::now()->subDay());
    N::issues()->updateStatus($first->issueId, IssueStatus::Resolved, CarbonImmutable::now()->subHours(2));
    $regressed = N::record();

    notifDispatcher()->notifyIssue($regressed->issueId);

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'Regression: Slow query on orders')
        && str_contains($request->body(), 'came back after being resolved'));
});
