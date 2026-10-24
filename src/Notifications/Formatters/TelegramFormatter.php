<?php

declare(strict_types=1);

namespace Anabil\Tracing\Notifications\Formatters;

use Anabil\Tracing\Data\NotificationMessage;

/**
 * NotificationMessage -> Telegram Bot API sendMessage text (parse_mode=HTML).
 *
 * Every piece of text is HTML-escaped; tags are only ever emitted in balanced pairs.
 * The 4096-character limit is respected by adding sections in priority order and truncating
 * the first one that does not fit (on raw text, before escaping, so no entity or tag is cut).
 */
final class TelegramFormatter
{
    public const LIMIT = 4096;

    private const ELLIPSIS = '…';

    private const MIN_TRUNCATED = 24;

    /**
     * @return array{text: string, parse_mode: string, disable_web_page_preview: bool}
     */
    public function format(NotificationMessage $message): array
    {
        return [
            'text' => $this->text($message),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];
    }

    public function text(NotificationMessage $message): string
    {
        // Each section: [opening markup, raw text, closing markup]. Markup is already safe HTML.
        $sections = [
            [$message->emoji().' <b>', $message->title, '</b>'."\n"],
            ['', $message->summary, "\n"],
        ];

        if ($message->fields !== []) {
            $sections[] = ['', '', "\n"];
            foreach ($message->fields as $label => $value) {
                $sections[] = ['<b>'.self::escape((string) $label).':</b> ', $value, "\n"];
            }
        }

        if ($message->sql !== null && $message->sql !== '') {
            $sections[] = ["\n".'<pre><code class="language-sql">', $message->sql, '</code></pre>'."\n"];
        }

        if ($message->aiRecommendation !== null && $message->aiRecommendation !== '') {
            $sections[] = ["\n".'<b>💡 AI recommendation</b>'."\n", $message->aiRecommendation, "\n"];
        }

        if ($message->occurrences > 1) {
            $sections[] = ["\n".'<i>', 'Occurrences in this message: '.$message->occurrences, '</i>'."\n"];
        }

        if ($message->footer !== null && $message->footer !== '') {
            $sections[] = ["\n".'<i>', $message->footer, '</i>'];
        }

        // The dashboard link is reserved up front so truncation never drops it.
        $link = $message->url !== null && $message->url !== ''
            ? "\n\n".'<a href="'.self::escape($message->url).'">Open in dashboard</a>'
            : '';

        $budget = self::LIMIT - mb_strlen($link);
        $text = '';

        foreach ($sections as [$open, $raw, $close]) {
            $escaped = self::escape($raw);
            $length = mb_strlen($open) + mb_strlen($escaped) + mb_strlen($close);

            if (mb_strlen($text) + $length <= $budget) {
                $text .= $open.$escaped.$close;

                continue;
            }

            $room = $budget - mb_strlen($text) - mb_strlen($open) - mb_strlen($close);
            if ($room >= self::MIN_TRUNCATED) {
                $text .= $open.self::escapeWithin($raw, $room).$close;
            }

            break;
        }

        return rtrim($text).$link;
    }

    /** Telegram HTML needs only &, < and > escaped (plus quotes inside attributes). */
    public static function escape(string $text): string
    {
        return str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], mb_scrub($text, 'UTF-8'));
    }

    /** Escape $raw, cut on character boundaries so the escaped result (with "…") fits $max. */
    public static function escapeWithin(string $raw, int $max): string
    {
        $escaped = self::escape($raw);
        if (mb_strlen($escaped) <= $max) {
            return $escaped;
        }

        $result = '';
        $length = 0;
        $limit = $max - mb_strlen(self::ELLIPSIS);

        foreach (mb_str_split($raw) as $char) {
            $piece = self::escape($char);
            $pieceLength = mb_strlen($piece);
            if ($length + $pieceLength > $limit) {
                break;
            }

            $result .= $piece;
            $length += $pieceLength;
        }

        return $result.self::ELLIPSIS;
    }
}
