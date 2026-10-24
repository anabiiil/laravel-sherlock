<?php

declare(strict_types=1);

use Anabil\Tracing\Contracts\NotificationDispatcher;
use Anabil\Tracing\Data\NotificationMessage;
use Anabil\Tracing\Digest\DigestBuilder;
use Anabil\Tracing\Enums\Severity;
use Anabil\Tracing\Notifications\DefaultNotificationDispatcher;
use Anabil\Tracing\Notifications\Mail\IssueNotificationMail;
use Anabil\Tracing\Tests\Feature\Notifications\NotificationFixtures as N;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

function notifDigest(Severity $severity = Severity::Info, array $fields = ['Top issues' => '1. Slow query on orders']): NotificationMessage
{
    return new NotificationMessage(
        title: 'Tracing digest: Shop',
        severity: $severity,
        summary: 'Weekly summary for ops@example.com',
        fields: $fields,
        url: 'https://example.test/tracing',
        footer: 'Periodic digest from stored data. No new AI calls were made.',
        fingerprint: DigestBuilder::FINGERPRINT,
    );
}

/** @return list<string> */
function notifDigestHosts(): array
{
    return array_values(array_map(static fn (array $pair): string => parse_url($pair[0]->url(), PHP_URL_HOST), Http::recorded()->all()));
}

beforeEach(function (): void {
    // Only slack is listed; mail would need "critical", the global minimum is "warning".
    N::configure([
        'channels' => ['slack'],
        'min_severity' => 'warning',
        'mail' => ['min_severity' => 'critical'],
        'telegram' => ['min_severity' => 'critical'],
    ]);
    N::fakeHttp();
    Mail::fake();
});

it('detects digest messages by their fingerprint', function (): void {
    expect(DefaultNotificationDispatcher::isDigest(notifDigest()))->toBeTrue()
        ->and(DefaultNotificationDispatcher::isDigest(new NotificationMessage('x', Severity::Info, 'y', fingerprint: 'issue')))->toBeFalse();
});

it('sends a digest to the enabled channels regardless of severity thresholds', function (?array $channels): void {
    app(NotificationDispatcher::class)->send(notifDigest(), $channels);

    expect(notifDigestHosts())->toBe(['hooks.slack.test']);
    Mail::assertNothingSent();
})->with(['null' => [null], 'empty' => [[]]]);

it('honours explicit digest channels even when not listed in notifications.channels', function (): void {
    app(NotificationDispatcher::class)->send(notifDigest(), ['mail', 'Telegram']);

    expect(notifDigestHosts())->toBe(['api.telegram.org']);
    Mail::assertSent(IssueNotificationMail::class, fn (IssueNotificationMail $mail): bool => $mail->hasTo('ops@example.test')
        && str_contains($mail->subjectLine, 'Tracing digest: Shop'));
});

it('still requires the global switch, credentials and redaction for digests', function (): void {
    N::configure(['enabled' => false]);
    app(NotificationDispatcher::class)->send(notifDigest(), ['slack']);
    Http::assertNothingSent();

    N::configure(['channels' => ['slack'], 'telegram' => ['bot_token' => null]]);
    app(NotificationDispatcher::class)->send(notifDigest(), ['telegram', 'slack', 'pigeon']);

    expect(notifDigestHosts())->toBe(['hooks.slack.test']);
    Http::assertSent(fn (Request $request): bool => ! str_contains($request->body(), 'ops@example.com')
        && str_contains($request->body(), '[REDACTED]'));
});

it('keeps severity thresholds for regular messages', function (): void {
    app(NotificationDispatcher::class)->send(new NotificationMessage('Slow', Severity::Info, 'x'), ['slack', 'mail']);

    Http::assertNothingSent();
    Mail::assertNothingSent();
});

it('delivers a long 10-item digest to telegram within the 4096 character limit', function (): void {
    $fields = [];
    foreach (['New issues', 'Regressed', 'Most frequent', 'Slowest entries', 'Top queries', 'AI recommendations'] as $section) {
        $lines = [];
        for ($i = 1; $i <= 10; $i++) {
            $lines[] = $i.'. select * from `orders` where `user_id` = ? and `total` > ? <'.str_repeat('x', 60).'> & more';
        }
        $fields[$section] = implode("\n", $lines);
    }

    app(NotificationDispatcher::class)->send(notifDigest(Severity::Warning, $fields), ['telegram']);

    $text = Http::recorded()->first()[0]['text'];

    expect(mb_strlen($text))->toBeLessThanOrEqual(4096)
        ->and($text)->toContain('<b>New issues:</b>')
        ->and($text)->toEndWith('<a href="https://example.test/tracing">Open in dashboard</a>')
        ->and(substr_count($text, '<b>'))->toBe(substr_count($text, '</b>'))
        ->and(preg_match('/&[a-z]*…/', $text))->toBe(0);
});
