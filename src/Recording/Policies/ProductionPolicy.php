<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Recording\Policies;

use Anabil\Sherlock\Contracts\RecordingPolicy;
use Anabil\Sherlock\Data\EntryCandidate;
use Anabil\Sherlock\Data\RecordingDecision;
use Anabil\Sherlock\Enums\RecordReason;
use Closure;

/**
 * mode=production: lightweight capture of every unit (no bindings). Thresholds are applied
 * later by the TraceRecorder; a "sample_rate" fraction is additionally marked Sample so it
 * is persisted as a baseline even without findings.
 */
final class ProductionPolicy implements RecordingPolicy
{
    private readonly float $sampleRate;

    /** @var Closure(): float */
    private readonly Closure $random;

    /**
     * @param  (Closure(): float)|null  $random  returns [0, 1); injectable for tests
     */
    public function __construct(float $sampleRate, ?Closure $random = null)
    {
        $this->sampleRate = max(0.0, min(1.0, $sampleRate));
        $this->random = $random ?? static fn (): float => mt_rand() / (mt_getrandmax() + 1);
    }

    public function decide(EntryCandidate $candidate): RecordingDecision
    {
        if ($this->sampleRate > 0.0 && ($this->sampleRate >= 1.0 || ($this->random)() < $this->sampleRate)) {
            return RecordingDecision::light(RecordReason::Sample);
        }

        return RecordingDecision::light();
    }
}
