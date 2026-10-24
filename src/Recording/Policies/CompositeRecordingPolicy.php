<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Recording\Policies;

use Anabil\Sherlock\Contracts\RecordingPolicy;
use Anabil\Sherlock\Data\EntryCandidate;
use Anabil\Sherlock\Data\RecordingDecision;

/**
 * Composite: the IgnorePolicy runs first and short-circuits; the remaining policies are
 * merged (capture if any captures, full if any is full, reasons united). Evaluation stops
 * as soon as the decision is full and forces persistence: nothing can add to it, so e.g.
 * a valid trigger header skips the watch-window cache read entirely.
 */
final class CompositeRecordingPolicy implements RecordingPolicy
{
    /**
     * @param  list<RecordingPolicy>  $policies  evaluated in order (cheapest / strongest first)
     */
    public function __construct(
        private readonly array $policies,
        private readonly ?IgnorePolicy $ignore = null,
    ) {}

    public function decide(EntryCandidate $candidate): RecordingDecision
    {
        if ($this->ignore?->ignores($candidate) === true) {
            return RecordingDecision::skip();
        }

        $decision = RecordingDecision::skip();

        foreach ($this->policies as $policy) {
            $decision = $decision->merge($policy->decide($candidate));

            if ($decision->full && $decision->forcesPersistence()) {
                break;
            }
        }

        return $decision;
    }

    /**
     * @return list<RecordingPolicy>
     */
    public function policies(): array
    {
        return $this->policies;
    }
}
