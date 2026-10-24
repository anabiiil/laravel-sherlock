<?php

declare(strict_types=1);

use Anabil\Tracing\Notifications\Mail\IssueNotificationMail;
use Anabil\Tracing\Tests\Feature\Notifications\NotificationFixtures as N;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();
});

it('sends a sample to every configured channel, even before notifications are enabled', function (): void {
    N::configure(['enabled' => false, 'channels' => [], 'min_severity' => 'critical']);
    N::fakeHttp();

    $this->artisan('tracing:test-notification')
        ->expectsOutputToContain('slack')
        ->expectsOutputToContain('sent')
        ->assertSuccessful();

    expect(Http::recorded())->toHaveCount(2);
    Mail::assertSent(IssueNotificationMail::class, fn (IssueNotificationMail $mail): bool => str_contains($mail->subjectLine, 'Test notification'));
});

it('sends to a single channel and reports failures', function (): void {
    N::configure();
    N::fakeHttp(telegramStatus: 400);

    $this->artisan('tracing:test-notification', ['channel' => 'telegram'])
        ->expectsOutputToContain('chat not found')
        ->assertFailed();

    expect(Http::recorded())->toHaveCount(1);
    Mail::assertNothingSent();
});

it('reports channels that are not configured', function (): void {
    N::configure(['slack' => ['webhook_url' => null], 'telegram' => ['bot_token' => null], 'mail' => ['to' => []]]);
    Http::fake();

    $this->artisan('tracing:test-notification')
        ->expectsOutputToContain('not configured')
        ->expectsOutputToContain('No channel is configured')
        ->assertFailed();

    $this->artisan('tracing:test-notification', ['channel' => 'slack'])->assertFailed();

    Http::assertNothingSent();
});

it('rejects unknown channels', function (): void {
    $this->artisan('tracing:test-notification', ['channel' => 'pigeon'])
        ->expectsOutputToContain('Unknown channel [pigeon]')
        ->assertFailed();
});
