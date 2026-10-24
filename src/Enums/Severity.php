<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Enums;

enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    public function weight(): int
    {
        return match ($this) {
            self::Info => 1,
            self::Warning => 2,
            self::Critical => 3,
        };
    }

    public function isAtLeast(self $other): bool
    {
        return $this->weight() >= $other->weight();
    }

    public function isHigherThan(self $other): bool
    {
        return $this->weight() > $other->weight();
    }

    public static function max(self $a, self $b): self
    {
        return $a->weight() >= $b->weight() ? $a : $b;
    }

    /**
     * Resolve from a config value; unknown values fall back to $default.
     */
    public static function fromConfig(mixed $value, self $default = self::Warning): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return is_string($value) ? (self::tryFrom(strtolower(trim($value))) ?? $default) : $default;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Info => 'ℹ️',
            self::Warning => '⚠️',
            self::Critical => '🚨',
        };
    }
}
