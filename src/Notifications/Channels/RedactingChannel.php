<?php

declare(strict_types=1);

namespace Anabil\Tracing\Notifications\Channels;

use Anabil\Tracing\Contracts\NotificationChannel;
use Anabil\Tracing\Contracts\Redactor;
use Anabil\Tracing\Data\NotificationMessage;

/**
 * Decorator: every outgoing message passes through the Redactor again before the wrapped
 * channel formats it (defense in depth; issue data is already redacted at capture time).
 */
final class RedactingChannel implements NotificationChannel
{
    public function __construct(
        private readonly NotificationChannel $inner,
        private readonly Redactor $redactor,
    ) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    public function isConfigured(): bool
    {
        return $this->inner->isConfigured();
    }

    public function send(NotificationMessage $message): void
    {
        $this->inner->send(self::redact($message, $this->redactor));
    }

    public function inner(): NotificationChannel
    {
        return $this->inner;
    }

    public static function redact(NotificationMessage $message, Redactor $redactor): NotificationMessage
    {
        $fields = [];
        foreach ($message->fields as $label => $value) {
            $label = (string) $label;
            $fields[$redactor->redactString($label)] = $redactor->isSensitiveKey($label)
                ? Redactor::REDACTED
                : $redactor->redactString($value);
        }

        $text = static fn (?string $value): ?string => $value === null ? null : $redactor->redactString($value);

        return new NotificationMessage(
            title: $redactor->redactString($message->title),
            severity: $message->severity,
            summary: $redactor->redactString($message->summary),
            fields: $fields,
            sql: $text($message->sql),
            aiRecommendation: $text($message->aiRecommendation),
            url: $message->url === null ? null : $redactor->redactUrl($message->url),
            occurrences: $message->occurrences,
            footer: $text($message->footer),
            issueId: $message->issueId,
            fingerprint: $message->fingerprint,
        );
    }
}
