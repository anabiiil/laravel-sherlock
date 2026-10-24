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
    /**
     * @param  array<string, string>  $fields  label => value (verdict, location, metrics...)
     * @param  string|null  $sql  normalized SQL only
     * @param  int  $occurrences  occurrences represented by this message (throttled summaries > 1)
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
    ) {}

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
        ];
    }
}
