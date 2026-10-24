<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Digest;

use Anabil\Sherlock\Data\EntryDelta;
use Anabil\Sherlock\Data\ReleaseComparison;
use Anabil\Sherlock\Enums\EntryType;

/**
 * One-line, plain-text summary of a release comparison, shared by the Deploys page, the
 * sherlock:report command and the digest: "3 routes slower, 2 new issues since deploy abc123".
 */
final class DeploySummary
{
    public static function headline(ReleaseComparison $comparison): string
    {
        $slower = $comparison->slower();
        $parts = [];

        if ($slower !== []) {
            $parts[] = count($slower).' '.self::noun($slower).' slower';
        }

        if ($comparison->newIssues !== []) {
            $parts[] = self::count(count($comparison->newIssues), 'new issue');
        }

        if ($comparison->regressedIssues !== []) {
            $parts[] = self::count(count($comparison->regressedIssues), 'regressed issue');
        }

        if ($parts === []) {
            $faster = $comparison->faster();

            return $faster === []
                ? 'No slower entries and no new issues since deploy '.$comparison->current
                : 'No regressions since deploy '.$comparison->current.'; '.count($faster).' '.self::noun($faster).' faster';
        }

        return implode(', ', $parts).' since deploy '.$comparison->current;
    }

    /**
     * "route" / "routes", "job(s)", "command(s)", or "entries" when the types are mixed.
     *
     * @param  list<EntryDelta>  $deltas
     */
    public static function noun(array $deltas): string
    {
        $types = array_values(array_unique(array_map(static fn (EntryDelta $d): string => $d->type->value, $deltas)));
        $plural = count($deltas) !== 1;

        if (count($types) !== 1) {
            return $plural ? 'entries' : 'entry';
        }

        $noun = match (EntryType::from($types[0])) {
            EntryType::Request => 'route',
            EntryType::Job => 'job',
            EntryType::Command => 'command',
        };

        return $plural ? $noun.'s' : $noun;
    }

    private static function count(int $count, string $noun): string
    {
        return $count.' '.$noun.($count === 1 ? '' : 's');
    }
}
