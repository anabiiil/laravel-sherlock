<?php

declare(strict_types=1);

namespace Anabil\Tracing\Recording\Policies;

use Anabil\Tracing\Contracts\RecordingPolicy;
use Anabil\Tracing\Data\EntryCandidate;
use Anabil\Tracing\Data\RecordingDecision;
use Anabil\Tracing\Enums\EntryType;
use Anabil\Tracing\Enums\RecordReason;

/**
 * "X-Tracing: <secret>" fully traces that single request. Constant-time comparison;
 * disabled while the secret is empty.
 */
final class HeaderTriggerPolicy implements RecordingPolicy
{
    public function __construct(
        private readonly ?string $secret,
        private readonly bool $enabled = true,
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled && $this->secret !== null && $this->secret !== '';
    }

    public function decide(EntryCandidate $candidate): RecordingDecision
    {
        if ($candidate->type !== EntryType::Request
            || $candidate->triggerHeader === null
            || $candidate->triggerHeader === ''
            || ! $this->isEnabled()) {
            return RecordingDecision::skip();
        }

        return hash_equals((string) $this->secret, $candidate->triggerHeader)
            ? RecordingDecision::full(RecordReason::Header)
            : RecordingDecision::skip();
    }
}
