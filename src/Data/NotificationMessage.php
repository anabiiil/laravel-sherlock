<?php

declare(strict_types=1);

namespace Anabil\Tracing\Data;

use Anabil\Tracing\Enums\Severity;

/**
 * Channel-agnostic notification. Channels format it (Slack blocks, Telegram HTML, Mail markdown);
 * the dispatcher redacts it again before sending.
 */
final readonly class NotificationMessage
{
    public const KIND_DIGEST = 'digest';

    public const KIND_TEST = 'test';

    private const NEUTRAL_EMOJI = '🔔';

    private const NEUTRAL_COLOR = '#57606A';

    /**
     * @param  array<string, string>  $fields  label => value (verdict, location, metrics...)
     * @param  string|null  $sql  normalized SQL only
     * @param  int  $occurrences  occurrences represented by this message (throttled summaries > 1)
     * @param  string|null  $kind  non-issue message (self::KIND_DIGEST / self::KIND_TEST): its severity only
     *                             routes it to channels and is never shown as a label (subjects, headers)
     */
    public function __construct(
        public string $title,
        public Severity $severity,
        public string $summary,
        public array $fields = [],
        public ?string $sql = null,
        public ?string $aiRecommendation = null,
        public ?string $url = null,
        public int $occurrences = 1,
        public ?string $footer = null,
        public int|string|null $issueId = null,
        public ?string $fingerprint = null,
        public ?string $kind = null,
    ) {}

    /** "Critical", or null for digests / test messages (no severity label). */
    public function severityLabel(): ?string
    {
        return $this->kind === null ? $this->severity->label() : null;
    }

    public function emoji(): string
    {
        return $this->kind === null ? $this->severity->emoji() : self::NEUTRAL_EMOJI;
    }

    /** Accent color: the severity's, neutral for digests / test messages. */
    public function color(): string
    {
        return $this->kind === null ? self::severityColor($this->severity) : self::NEUTRAL_COLOR;
    }

    public static function severityColor(Severity $severity): string
    {
        return match ($severity) {
            Severity::Critical => '#E01E5A',
            Severity::Warning => '#ECB22E',
            Severity::Info => '#36C5F0',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'severity' => $this->severity->value,
            'summary' => $this->summary,
            'fields' => $this->fields,
            'sql' => $this->sql,
            'ai_recommendation' => $this->aiRecommendation,
            'url' => $this->url,
            'occurrences' => $this->occurrences,
            'footer' => $this->footer,
            'issue_id' => $this->issueId,
            'fingerprint' => $this->fingerprint,
            'kind' => $this->kind,
        ];
    }
}
