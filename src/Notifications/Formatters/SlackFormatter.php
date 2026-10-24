<?php

declare(strict_types=1);

namespace Anabil\Tracing\Notifications\Formatters;

use Anabil\Tracing\Data\NotificationMessage;
use Anabil\Tracing\Enums\Severity;

/**
 * NotificationMessage -> Slack incoming-webhook payload (Block Kit inside a colored attachment).
 * Respects Block Kit limits: header 150 chars, section text 3000, 10 fields of 2000.
 */
final class SlackFormatter
{
    private const HEADER_LIMIT = 150;

    private const TEXT_LIMIT = 3000;

    private const FIELD_LIMIT = 2000;

    private const MAX_FIELDS = 10;

    public static function color(Severity $severity): string
    {
        return NotificationMessage::severityColor($severity);
    }

    /**
     * @param  array<string, mixed>  $options  username / channel / icon_emoji
     * @return array<string, mixed>
     */
    public function format(NotificationMessage $message, array $options = []): array
    {
        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => self::limit($message->emoji().' '.$message->title, self::HEADER_LIMIT),
                    'emoji' => true,
                ],
            ],
            [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => self::limit(self::escape($message->summary), self::TEXT_LIMIT)],
            ],
        ];

        foreach (array_chunk($message->fields, self::MAX_FIELDS, true) as $chunk) {
            $fields = [];
            foreach ($chunk as $label => $value) {
                $fields[] = [
                    'type' => 'mrkdwn',
                    'text' => self::limit('*'.self::escape((string) $label).'*'."\n".self::escape($value), self::FIELD_LIMIT),
                ];
            }

            $blocks[] = ['type' => 'section', 'fields' => $fields];
        }

        if ($message->sql !== null && $message->sql !== '') {
            $blocks[] = [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => self::code($message->sql)],
            ];
        }

        if ($message->aiRecommendation !== null && $message->aiRecommendation !== '') {
            $blocks[] = ['type' => 'divider'];
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => self::limit("*:bulb: AI recommendation*\n".self::escape($message->aiRecommendation), self::TEXT_LIMIT),
                ],
            ];
        }

        $context = [];
        if ($message->occurrences > 1) {
            $context[] = ['type' => 'mrkdwn', 'text' => 'Occurrences in this message: *'.$message->occurrences.'*'];
        }
        if ($message->footer !== null && $message->footer !== '') {
            $context[] = ['type' => 'mrkdwn', 'text' => self::limit(self::escape($message->footer), self::FIELD_LIMIT)];
        }
        if ($context !== []) {
            $blocks[] = ['type' => 'context', 'elements' => $context];
        }

        if ($message->url !== null && $message->url !== '') {
            $blocks[] = [
                'type' => 'actions',
                'elements' => [[
                    'type' => 'button',
                    'text' => ['type' => 'plain_text', 'text' => 'Open in dashboard'],
                    'url' => $message->url,
                ]],
            ];
        }

        $payload = [
            // Fallback for push notifications / clients without Block Kit.
            'text' => self::limit(($message->severityLabel() !== null ? $message->severityLabel().': ' : '').$message->title, self::HEADER_LIMIT * 2),
            'attachments' => [[
                'color' => $message->color(),
                'blocks' => $blocks,
            ]],
        ];

        foreach (['username', 'channel', 'icon_emoji'] as $option) {
            if (isset($options[$option]) && is_string($options[$option]) && $options[$option] !== '') {
                $payload[$option] = $options[$option];
            }
        }

        return $payload;
    }

    /** Slack mrkdwn control characters. */
    public static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    private static function code(string $sql): string
    {
        $sql = str_replace('```', "'''", $sql);
        $budget = self::TEXT_LIMIT - 8;

        return "```\n".self::limit(self::escape($sql), $budget)."\n```";
    }

    private static function limit(string $text, int $limit): string
    {
        return mb_strlen($text) <= $limit ? $text : rtrim(mb_substr($text, 0, $limit - 1)).'…';
    }
}
