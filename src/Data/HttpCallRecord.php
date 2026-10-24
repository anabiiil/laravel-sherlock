<?php

declare(strict_types=1);

namespace Anabil\Tracing\Data;

/**
 * One outgoing HTTP call. The URL is already redacted (sensitive query values masked).
 */
final readonly class HttpCallRecord
{
    public function __construct(
        public string $method,
        public string $url,
        public ?int $statusCode,
        public float $durationMs,
        public float $offsetMs = 0.0,
        public ?SourceLocation $location = null,
        public ?string $error = null,
    ) {}

    public function host(): string
    {
        $host = parse_url($this->url, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    public function failed(): bool
    {
        return $this->error !== null || $this->statusCode === null || $this->statusCode >= 500;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'url' => $this->url,
            'host' => $this->host(),
            'status_code' => $this->statusCode,
            'duration_ms' => $this->durationMs,
            'offset_ms' => $this->offsetMs,
            'location' => $this->location?->toArray(),
            'error' => $this->error,
        ];
    }
}
