<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Releases;

use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Contracts\TraceRepository;
use Anabil\Sherlock\Data\EntryDelta;
use Anabil\Sherlock\Data\EntryStat;
use Anabil\Sherlock\Data\ReleaseComparison;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\RecordReason;

/**
 * Before / after deploy comparison of two releases: per-entry duration deltas plus the issues
 * the current release introduced, brought back, or no longer shows.
 *
 * Sampling bias: in production only sampled traces (sample_rate) and threshold traces are
 * persisted, so the average of all stored traces of an entry overweights slow executions. Per
 * entry, the "sample" population (record_reason = sample) is used when both releases have at
 * least $minSamples of them; otherwise every persisted trace ("all", biased, flagged in
 * EntryDelta::$basis). Local mode stores everything, so "all" is exact there.
 *
 * Read-only, dashboard / report time: 4 grouped trace queries + 3 issue queries.
 */
final class ReleaseComparator
{
    private const ORDER = [
        EntryDelta::SLOWER => 0,
        EntryDelta::NEW => 1,
        EntryDelta::FASTER => 2,
        EntryDelta::UNCHANGED => 3,
        EntryDelta::GONE => 4,
    ];

    /**
     * @param  float  $minChange  relative change of the average to call an entry slower / faster (0.1 = 10%)
     * @param  float  $minDeltaMs  absolute change of the average needed as well (ignores 2ms -> 3ms)
     */
    public function __construct(
        private readonly TraceRepository $traces,
        private readonly IssueRepository $issues,
        private readonly int $minSamples = 20,
        private readonly float $minChange = 0.1,
        private readonly float $minDeltaMs = 5.0,
    ) {}

    public function compare(string $current, string $previous, ?EntryType $type = null, int $limit = 50): ReleaseComparison
    {
        $limit = max(1, $limit);
        $fetch = min(1000, $limit * 4);

        $all = [
            'current' => self::index($this->traces->entryStatsForRelease($current, $type, $fetch)),
            'previous' => self::index($this->traces->entryStatsForRelease($previous, $type, $fetch)),
        ];
        $sampled = [
            'current' => self::index($this->traces->entryStatsForRelease($current, $type, $fetch, RecordReason::Sample)),
            'previous' => self::index($this->traces->entryStatsForRelease($previous, $type, $fetch, RecordReason::Sample)),
        ];

        // Entries of the current release first (most frequent first), then the ones that disappeared.
        $keys = array_slice(array_values(array_unique([...array_keys($all['current']), ...array_keys($all['previous'])])), 0, $limit);

        $entries = [];
        foreach ($keys as $key) {
            $entries[] = $this->delta(
                $all['current'][$key] ?? null,
                $all['previous'][$key] ?? null,
                $sampled['current'][$key] ?? null,
                $sampled['previous'][$key] ?? null,
            );
        }

        usort($entries, static function (EntryDelta $a, EntryDelta $b): int {
            $order = self::ORDER[$a->status] <=> self::ORDER[$b->status];
            if ($order !== 0) {
                return $order;
            }

            // Biggest regression first among slower, biggest gain first among faster.
            return $a->status === EntryDelta::FASTER
                ? ($a->deltaAvgMs ?? 0.0) <=> ($b->deltaAvgMs ?? 0.0)
                : ($b->deltaAvgMs ?? 0.0) <=> ($a->deltaAvgMs ?? 0.0);
        });

        return new ReleaseComparison(
            $current,
            $previous,
            $this->traces->findRelease($current),
            $this->traces->findRelease($previous),
            $entries,
            $this->issues->firstSeenInRelease($current, $limit),
            $this->issues->regressedInRelease($current, $limit),
            $this->issues->lastSeenInRelease($previous, $limit),
        );
    }

    /**
     * The two most recently deployed releases (by first seen), or null with fewer than two.
     */
    public function compareLatest(?EntryType $type = null, int $limit = 50): ?ReleaseComparison
    {
        $releases = $this->traces->releases(2);

        return count($releases) < 2 ? null : $this->compare($releases[0]->release, $releases[1]->release, $type, $limit);
    }

    private function delta(?EntryStat $current, ?EntryStat $previous, ?EntryStat $sampledCurrent, ?EntryStat $sampledPrevious): EntryDelta
    {
        $any = $current ?? $previous;
        assert($any instanceof EntryStat);

        if ($current === null || $previous === null) {
            return new EntryDelta($any->type, $any->name, $current, $previous, $current === null ? EntryDelta::GONE : EntryDelta::NEW);
        }

        $basis = EntryDelta::BASIS_ALL;
        if ($sampledCurrent !== null && $sampledPrevious !== null
            && $sampledCurrent->count >= $this->minSamples && $sampledPrevious->count >= $this->minSamples) {
            [$current, $previous, $basis] = [$sampledCurrent, $sampledPrevious, EntryDelta::BASIS_SAMPLE];
        }

        $delta = $current->avgMs - $previous->avgMs;
        $ratio = $previous->avgMs > 0.0 ? $delta / $previous->avgMs : null;

        $status = match (true) {
            $ratio !== null && $ratio >= $this->minChange && $delta >= $this->minDeltaMs => EntryDelta::SLOWER,
            $ratio !== null && $ratio <= -$this->minChange && -$delta >= $this->minDeltaMs => EntryDelta::FASTER,
            $ratio === null && $delta >= $this->minDeltaMs => EntryDelta::SLOWER,
            default => EntryDelta::UNCHANGED,
        };

        return new EntryDelta(
            $current->type,
            $current->name,
            $current,
            $previous,
            $status,
            $basis,
            round($delta, 3),
            $ratio === null ? null : round($ratio, 4),
            round($current->avgDbMs - $previous->avgDbMs, 3),
            round($current->avgQueries - $previous->avgQueries, 2),
        );
    }

    /**
     * @param  list<EntryStat>  $stats
     * @return array<string, EntryStat> "type name" => stat
     */
    private static function index(array $stats): array
    {
        $indexed = [];
        foreach ($stats as $stat) {
            $indexed[$stat->type->value.' '.$stat->name] = $stat;
        }

        return $indexed;
    }
}
