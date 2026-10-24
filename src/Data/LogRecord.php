<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Data;

/**
 * A log entry or exception. Message, context and frames are already redacted;
 * frame files are relative paths and never contain argument values.
 */
final readonly class LogRecord
{
    private const LEVEL_WEIGHTS = [
        'debug' => 100,
        'info' => 200,
        'notice' => 250,
        'warning' => 300,
        'error' => 400,
        'critical' => 500,
        'alert' => 550,
        'emergency' => 600,
    ];

    /**
     * @param  list<array{file: string, line: int, class?: string|null, function?: string|null}>  $frames
     * @param  array<array-key, mixed>  $context
     */
    public function __construct(
        public string $level,
        public string $message,
        public ?string $exceptionClass = null,
        public ?string $file = null,
        public ?int $line = null,
        public array $frames = [],
        public array $context = [],
        public float $offsetMs = 0.0,
    ) {}

    public function isException(): bool
    {
        return $this->exceptionClass !== null;
    }

    public static function levelWeight(string $level): int
    {
        return self::LEVEL_WEIGHTS[strtolower($level)] ?? 0;
    }

    public function isAtLeast(string $level): bool
    {
        return self::levelWeight($this->level) >= self::levelWeight($level);
    }

    public function location(): ?SourceLocation
    {
        return $this->file !== null && $this->line !== null ? new SourceLocation($this->file, $this->line) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'message' => $this->message,
            'exception_class' => $this->exceptionClass,
            'file' => $this->file,
            'line' => $this->line,
            'frames' => $this->frames,
            'context' => $this->context,
            'offset_ms' => $this->offsetMs,
        ];
    }
}
