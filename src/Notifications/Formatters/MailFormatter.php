<?php

declare(strict_types=1);

namespace Anabil\Tracing\Notifications\Formatters;

use Anabil\Tracing\Data\NotificationMessage;

/**
 * NotificationMessage -> subject + view data for the tracing::mail.notification views.
 * Escaping is left to Blade ({{ }}), the views never print raw HTML.
 */
final class MailFormatter
{
    public const HTML_VIEW = 'tracing::mail.notification';

    public const TEXT_VIEW = 'tracing::mail.notification-text';

    private const SUBJECT_LIMIT = 180;

    public function subject(NotificationMessage $message): string
    {
        // Digests / test messages carry no severity label: "[Tracing] Digest: Shop".
        $label = $message->severityLabel();
        $subject = '[Tracing] '.($label !== null ? $label.': ' : '').preg_replace('/\s+/', ' ', $message->title);

        return mb_strlen($subject) <= self::SUBJECT_LIMIT ? $subject : mb_substr($subject, 0, self::SUBJECT_LIMIT - 1).'…';
    }

    /**
     * @return array{title: string, emoji: string, severity: string, color: string, summary: string, fields: array<string, string>, sql: string|null, ai: string|null, url: string|null, occurrences: int, footer: string|null}
     */
    public function data(NotificationMessage $message): array
    {
        return [
            'title' => $message->title,
            'emoji' => $message->emoji(),
            'severity' => $message->severityLabel() ?? '',
            'color' => $message->color(),
            'summary' => $message->summary,
            'fields' => $message->fields,
            'sql' => $message->sql !== '' ? $message->sql : null,
            'ai' => $message->aiRecommendation !== '' ? $message->aiRecommendation : null,
            'url' => $message->url !== '' ? $message->url : null,
            'occurrences' => $message->occurrences,
            'footer' => $message->footer,
        ];
    }
}
