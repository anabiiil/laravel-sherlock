<?php

declare(strict_types=1);

namespace Anabil\Tracing\Enums;

enum RecordReason: string
{
    case Local = 'local';
    case Threshold = 'threshold';
    case Sample = 'sample';
    case Watch = 'watch';
    case Header = 'header';

    /**
     * Reasons that force persistence regardless of findings.
     */
    public function forcesPersistence(): bool
    {
        return $this !== self::Threshold;
    }

    /**
     * Reasons that imply full capture (bindings, source locations).
     */
    public function impliesFullCapture(): bool
    {
        return match ($this) {
            self::Local, self::Watch, self::Header => true,
            default => false,
        };
    }

    /**
     * Highest-priority reason of a list (header > watch > local > threshold > sample).
     *
     * @param  list<self>  $reasons
     */
    public static function primary(array $reasons): ?self
    {
        foreach ([self::Header, self::Watch, self::Local, self::Threshold, self::Sample] as $candidate) {
            if (in_array($candidate, $reasons, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
