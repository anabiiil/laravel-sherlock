<?php

declare(strict_types=1);

namespace Anabil\Tracing\Notifications\Channels;

use Anabil\Tracing\Contracts\NotificationChannel;
use Anabil\Tracing\Data\NotificationMessage;
use Anabil\Tracing\Notifications\Formatters\MailFormatter;
use Anabil\Tracing\Notifications\Mail\IssueNotificationMail;
use Anabil\Tracing\Notifications\NotificationFailed;
use Anabil\Tracing\Notifications\NotificationPolicy;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * Laravel Mailer (notifications.mail.to, optional notifications.mail.mailer). The mail is sent
 * synchronously: this already runs inside a queued job.
 */
final class MailChannel implements NotificationChannel
{
    public function __construct(
        private readonly NotificationPolicy $policy,
        private readonly MailFormatter $formatter = new MailFormatter,
    ) {}

    public function name(): string
    {
        return 'mail';
    }

    public function isConfigured(): bool
    {
        return $this->recipients() !== [] && class_exists(Mailable::class);
    }

    public function send(NotificationMessage $message): void
    {
        $recipients = $this->recipients();
        if ($recipients === []) {
            throw new NotificationFailed('mail: no valid recipients configured (notifications.mail.to)');
        }

        $mailer = $this->policy->channelConfig('mail')['mailer'] ?? null;

        Mail::mailer(is_string($mailer) && $mailer !== '' ? $mailer : null)
            ->to($recipients)
            ->send(new IssueNotificationMail($this->formatter->subject($message), $this->formatter->data($message)));
    }

    /**
     * @return list<string>
     */
    public function recipients(): array
    {
        $to = $this->policy->channelConfig('mail')['to'] ?? [];

        if (is_string($to)) {
            $to = explode(',', $to);
        }

        if (! is_array($to)) {
            return [];
        }

        $recipients = [];
        foreach ($to as $address) {
            if (is_string($address) && filter_var(trim($address), FILTER_VALIDATE_EMAIL) !== false) {
                $recipients[] = trim($address);
            }
        }

        return array_values(array_unique($recipients));
    }
}
