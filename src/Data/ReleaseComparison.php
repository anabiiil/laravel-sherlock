<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Data;

/**
 * Before / after deploy comparison (ReleaseComparator::compare()).
 */
final readonly class ReleaseComparison
{
    /**
     * @param  list<EntryDelta>  $entries  slower first (largest regression first), then new, faster, unchanged, gone
     * @param  list<IssueRecord>  $newIssues  first seen in the current release
     * @param  list<IssueRecord>  $regressedIssues  came back (after being resolved) while the current release ran
     * @param  list<IssueRecord>  $goneIssues  last seen in the previous release (not seen since: maybe fixed)
     */
    public function __construct(
        public string $current,
        public string $previous,
        public ?ReleaseRecord $currentRelease,
        public ?ReleaseRecord $previousRelease,
        public array $entries,
        public array $newIssues = [],
        public array $regressedIssues = [],
        public array $goneIssues = [],
    ) {}

    /**
     * @return list<EntryDelta>
     */
    public function slower(): array
    {
        return array_values(array_filter($this->entries, static fn (EntryDelta $d): bool => $d->isSlower()));
    }

    /**
     * @return list<EntryDelta>
     */
    public function faster(): array
    {
        return array_values(array_filter($this->entries, static fn (EntryDelta $d): bool => $d->isFaster()));
    }

    /** Anything that got worse: a slower entry, a new or a regressed issue. */
    public function hasRegressions(): bool
    {
        return $this->slower() !== [] || $this->newIssues !== [] || $this->regressedIssues !== [];
    }
}
