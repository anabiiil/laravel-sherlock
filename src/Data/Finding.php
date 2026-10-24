<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Data;

use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;

/**
 * A problem detected by an analyzer in one trace. "fingerprint" is the issue
 * fingerprint (Fingerprinter::issue()) used for dedup, notifications and AI caching.
 */
final readonly class Finding
{
    /**
     * @param  array<string, scalar|null>  $metrics  e.g. ['time_ms' => 912.4, 'count' => 38]
     * @param  string|null  $sql  normalized SQL (placeholders only), never raw values
     * @param  float  $impactMs  time cost attributed to this problem in this trace (summed per issue as impact_ms)
     */
    public function __construct(
        public IssueType $type,
        public Severity $severity,
        public string $title,
        public string $description,
        public string $fingerprint,
        public array $metrics = [],
        public ?SourceLocation $location = null,
        public ?string $sql = null,
        public ?string $queryFingerprint = null,
        public ?string $recommendation = null,
        public float $impactMs = 0.0,
    ) {}

    public function withSeverity(Severity $severity): self
    {
        return new self(
            $this->type, $severity, $this->title, $this->description, $this->fingerprint,
            $this->metrics, $this->location, $this->sql, $this->queryFingerprint, $this->recommendation, $this->impactMs,
        );
    }

    public function withRecommendation(?string $recommendation): self
    {
        return new self(
            $this->type, $this->severity, $this->title, $this->description, $this->fingerprint,
            $this->metrics, $this->location, $this->sql, $this->queryFingerprint, $recommendation, $this->impactMs,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'severity' => $this->severity->value,
            'title' => $this->title,
            'description' => $this->description,
            'fingerprint' => $this->fingerprint,
            'metrics' => $this->metrics,
            'location' => $this->location?->toArray(),
            'sql' => $this->sql,
            'query_fingerprint' => $this->queryFingerprint,
            'recommendation' => $this->recommendation,
            'impact_ms' => $this->impactMs,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  output of toArray()
     */
    public static function fromArray(array $data): self
    {
        /** @var array{file: string, line: int, class?: string|null, function?: string|null}|null $location */
        $location = $data['location'] ?? null;
        /** @var array<string, scalar|null> $metrics */
        $metrics = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];

        return new self(
            IssueType::from((string) $data['type']),
            Severity::from((string) $data['severity']),
            (string) $data['title'],
            (string) ($data['description'] ?? ''),
            (string) $data['fingerprint'],
            $metrics,
            is_array($location) ? SourceLocation::fromArray($location) : null,
            isset($data['sql']) ? (string) $data['sql'] : null,
            isset($data['query_fingerprint']) ? (string) $data['query_fingerprint'] : null,
            isset($data['recommendation']) ? (string) $data['recommendation'] : null,
            is_numeric($data['impact_ms'] ?? null) ? max(0.0, (float) $data['impact_ms']) : 0.0,
        );
    }
}
