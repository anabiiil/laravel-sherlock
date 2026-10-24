<?php

declare(strict_types=1);

namespace Anabil\Tracing\Enums;

enum TracingMode: string
{
    case Off = 'off';
    case Local = 'local';
    case Production = 'production';

    /**
     * Resolve from a config value; unknown values fall back to production (the safe mode).
     */
    public static function fromConfig(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? self::Production : self::Off;
        }

        return self::tryFrom(strtolower(trim((string) (is_scalar($value) ? $value : '')))) ?? self::Production;
    }

    public function isEnabled(): bool
    {
        return $this !== self::Off;
    }

    public function isOff(): bool
    {
        return $this === self::Off;
    }

    /**
     * Whether every unit is fully recorded (bindings, source locations, persisted).
     */
    public function recordsEverything(): bool
    {
        return $this === self::Local;
    }
}
