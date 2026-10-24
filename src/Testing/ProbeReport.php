<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Testing;

use Anabil\Sherlock\Analysis\Support\Describe;
use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Data\SourceLocation;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Enums\IssueType;

/**
 * Plain-text failure details: what was found, the normalized SQL (placeholders only, never
 * values), how often it ran, the exact file:line in application code and how to fix it.
 */
final class ProbeReport
{
    /** Query groups listed when a query count assertion fails. */
    private const MAX_GROUPS = 15;

    /**
     * @param  list<Finding>  $findings
     * @param  TraceData|null  $trace  the analyzed trace: titles lose their short " in ..." suffix,
     *                                 the Location line gives the exact place instead
     */
    public static function findings(array $findings, ?TraceData $trace = null): string
    {
        $blocks = [];

        foreach ($findings as $index => $finding) {
            $lines = [($index + 1).') '.self::title($finding, $trace)];
            $lines[] = self::field('SQL', $finding->sql === null ? null : Describe::truncate($finding->sql, 500));
            $lines[] = self::field('Count', self::count($finding));
            $lines[] = self::field('Location', self::location($finding->location));
            $lines[] = self::field('Details', $finding->description);
            $lines[] = self::field('Fix', $finding->recommendation);

            $blocks[] = implode("\n", array_filter($lines, static fn (?string $line): bool => $line !== null));
        }

        return implode("\n\n", $blocks);
    }

    /**
     * Queries grouped by statement + location, most frequent first.
     */
    public static function queries(ProbeResult $result): string
    {
        /** @var array<string, array{sql: string, location: SourceLocation|null, count: int, ms: float}> $groups */
        $groups = [];
        foreach ($result->queries() as $query) {
            $key = ($query->fingerprint ?? $query->sql)."\0".($query->location?->toString() ?? '');
            $groups[$key] ??= ['sql' => $query->normalizedSql ?? $query->sql, 'location' => $query->location, 'count' => 0, 'ms' => 0.0];
            $groups[$key]['count']++;
            $groups[$key]['ms'] += $query->timeMs;
        }

        if ($groups === []) {
            return 'No queries ran.';
        }

        uasort($groups, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        $lines = [sprintf('Queries (%d, %s in total), grouped by statement and location:', $result->count(), self::ms($result->totalMs()))];
        foreach (array_slice($groups, 0, self::MAX_GROUPS) as $group) {
            $lines[] = sprintf(
                '  %4s  %s%s',
                $group['count'].'×',
                Describe::truncate($group['sql'], 160),
                $group['location'] === null ? '' : '  ('.self::location($group['location']).')',
            );
        }

        $hidden = count($groups) - self::MAX_GROUPS;
        if ($hidden > 0) {
            $lines[] = sprintf('  ... and %d more', $hidden);
        }

        return implode("\n", $lines);
    }

    /**
     * "app/Http/Controllers/OrderController.php:42 (App\Http\Controllers\OrderController::index)",
     * "tests/Feature/OrderTest.php:18 (closure)".
     */
    public static function location(?SourceLocation $location): ?string
    {
        if ($location === null) {
            return null;
        }

        $function = $location->function;
        $scope = match (true) {
            $function !== null && str_starts_with($function, '{closure') => 'closure',
            $function !== null && $location->class !== null => $location->class.'::'.$function,
            default => $function,
        };

        return $location->toString().($scope === null || $scope === '' ? '' : ' ('.$scope.')');
    }

    private static function title(Finding $finding, ?TraceData $trace): string
    {
        if ($trace === null || $finding->location === null) {
            return $finding->title;
        }

        $suffix = Describe::where($trace, $finding->location);

        return str_ends_with($finding->title, $suffix) ? substr($finding->title, 0, -strlen($suffix)) : $finding->title;
    }

    private static function count(Finding $finding): ?string
    {
        $count = $finding->metrics['count'] ?? null;
        if (! is_numeric($count)) {
            return null;
        }

        if ($finding->type === IssueType::ExcessiveQueries) {
            $db = $finding->metrics['db_ms'] ?? null;

            return sprintf('%d queries', (int) $count).(is_numeric($db) ? ', '.self::ms((float) $db).' in total' : '');
        }

        $total = $finding->metrics['total_ms'] ?? null;
        $wasted = $finding->metrics['wasted_ms'] ?? null;

        return sprintf('%d %s', (int) $count, (int) $count === 1 ? 'time' : 'times')
            .(is_numeric($total) ? ', '.self::ms((float) $total).' in total' : '')
            .(is_numeric($wasted) ? ', '.self::ms((float) $wasted).' wasted on repeats' : '');
    }

    private static function ms(float $ms): string
    {
        return $ms < 10 ? round($ms, 2).'ms' : Describe::duration($ms);
    }

    private static function field(string $label, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return '   '.str_pad($label.':', 10).$value;
    }
}
