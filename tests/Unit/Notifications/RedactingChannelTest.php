<?php

declare(strict_types=1);

use Anabil\Sherlock\Contracts\NotificationChannel;
use Anabil\Sherlock\Data\NotificationMessage;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Notifications\Channels\RedactingChannel;
use Anabil\Sherlock\Privacy\Redactor;

it('redacts every text part of the message before the inner channel sees it', function (): void {
    $inner = new class implements NotificationChannel
    {
        public ?NotificationMessage $received = null;

        public function name(): string
        {
            return 'spy';
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function send(NotificationMessage $message): void
        {
            $this->received = $message;
        }
    };

    $channel = new RedactingChannel($inner, Redactor::make());
    $channel->send(new NotificationMessage(
        title: 'Login failed for john@example.com',
        severity: Severity::Critical,
        summary: 'password=hunter2 rejected',
        fields: ['api_token' => 'abc123456789', 'Exception' => 'Bearer abcdefghijklmnop invalid', 'Location' => 'app/Http/Controllers/AuthController.php:42'],
        sql: 'select * from users where email = ?',
        aiRecommendation: 'Rotate secret=s3cr3tvalue now',
        url: 'https://example.test/sherlock/issues/1?token=abc123',
        occurrences: 3,
        footer: 'Sherlock · App',
        issueId: 1,
        fingerprint: 'fp',
    ));

    $sent = $inner->received;

    expect($channel->name())->toBe('spy')
        ->and($channel->isConfigured())->toBeTrue()
        ->and($channel->inner())->toBe($inner)
        ->and($sent->title)->toBe('Login failed for [REDACTED]')
        ->and($sent->summary)->toBe('password=[REDACTED] rejected')
        ->and($sent->fields['api_token'])->toBe('[REDACTED]')
        ->and($sent->fields['Exception'])->toBe('Bearer [REDACTED] invalid')
        ->and($sent->fields['Location'])->toBe('app/Http/Controllers/AuthController.php:42')
        ->and($sent->sql)->toBe('select * from users where email = ?')
        ->and($sent->aiRecommendation)->toBe('Rotate secret=[REDACTED] now')
        ->and($sent->url)->toBe('https://example.test/sherlock/issues/1?token=[REDACTED]')
        ->and($sent->occurrences)->toBe(3)
        ->and($sent->severity)->toBe(Severity::Critical)
        ->and($sent->issueId)->toBe(1)
        ->and($sent->fingerprint)->toBe('fp');
});
