<?php

declare(strict_types=1);

namespace Anabil\Tracing\Data;

use Anabil\Tracing\Enums\RecordReason;

/**
 * Decision of a RecordingPolicy for one unit:
 *  - capture: whether to collect anything at all;
 *  - full:    collect bindings and source locations (local / watch / header);
 *  - reasons: reasons that force persistence (local, watch, header, sample).
 *    An empty list means "persist only if a threshold is crossed".
 */
final readonly class RecordingDecision
{
    /**
     * @param  list<RecordReason>  $reasons
     */
    public function __construct(
        public bool $capture,
        public bool $full = false,
        public array $reasons = [],
    ) {}

    public static function skip(): self
    {
        return new self(false);
    }

    /**
     * Lightweight capture, persisted only on threshold (or with the given reasons, e.g. sample).
     */
    public static function light(RecordReason ...$reasons): self
    {
        return new self(true, false, array_values($reasons));
    }

    public static function full(RecordReason ...$reasons): self
    {
        return new self(true, true, array_values($reasons));
    }

    public function forcesPersistence(): bool
    {
        foreach ($this->reasons as $reason) {
            if ($reason->forcesPersistence()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Combine two decisions (Composite policy): capture if either captures, full if either is full,
     * reasons merged.
     */
    public function merge(self $other): self
    {
        $reasons = $this->reasons;
        foreach ($other->reasons as $reason) {
            if (! in_array($reason, $reasons, true)) {
                $reasons[] = $reason;
            }
        }

        return new self($this->capture || $other->capture, $this->full || $other->full, $reasons);
    }
}
