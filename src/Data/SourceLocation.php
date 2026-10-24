<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Data;

use Stringable;

/**
 * A location in application code. "file" is always relative to the app base path.
 */
final readonly class SourceLocation implements Stringable
{
    public function __construct(
        public string $file,
        public int $line,
        public ?string $class = null,
        public ?string $function = null,
    ) {}

    /**
     * @param  array{file: string, line: int, class?: string|null, function?: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['file'], $data['line'], $data['class'] ?? null, $data['function'] ?? null);
    }

    /**
     * @return array{file: string, line: int, class: string|null, function: string|null}
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'class' => $this->class,
            'function' => $this->function,
        ];
    }

    public function toString(): string
    {
        return $this->file.':'.$this->line;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
