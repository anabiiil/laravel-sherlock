<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Console;

use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Contracts\TraceRepository;
use Anabil\Sherlock\Data\EntryDelta;
use Anabil\Sherlock\Data\EntryStat;
use Anabil\Sherlock\Data\IssueFilter;
use Anabil\Sherlock\Data\IssueRecord;
use Anabil\Sherlock\Data\QueryStat;
use Anabil\Sherlock\Data\ReleaseRecord;
use Anabil\Sherlock\Digest\DeploySummary;
use Anabil\Sherlock\Enums\Bottleneck;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\IssueStatus;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Releases\ReleaseComparator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Terminal summary of the last N days from stored data: top open issues, top issues by
 * impact, slowest entries, top query fingerprints, the bottleneck distribution and the latest
 * deploy (latest release vs the previous one). --type filters the trace count, the slowest
 * entries and the deploy entries by unit type (request | job | command). --release=X compares
 * X with the release before it, --compare=a,b compares release a (after) with b (before).
 */
final class ReportCommand extends Command
{
    private const SQL_CHARS = 90;

    private const NAME_CHARS = 60;

    private const RELEASE_LOOKUP = 200;

    /** @var string */
    protected $signature = 'sherlock:report
        {--days=7 : Period to cover, in days}
        {--type= : Only this unit type for traces / slowest entries: request, job or command}
        {--limit=10 : Rows per table}
        {--release= : Deploy section: compare this release with the one deployed before it}
        {--compare= : Deploy section: compare two releases, "after,before" (e.g. --compare=abc123,def456)}';

    /** @var string */
    protected $description = 'Show a terminal summary: top issues (by occurrences and impact), slowest routes / jobs / commands, top queries, bottlenecks and the latest deploy';

    public function handle(TraceRepository $traces, IssueRepository $issues, ReleaseComparator $comparator): int
    {
        $days = $this->option('days');
        $limit = $this->option('limit');
        $typeOption = $this->option('type');

        if (! is_numeric($days) || (int) $days < 1) {
            $this->components->error('--days must be a positive number of days.');

            return self::FAILURE;
        }

        if (! is_numeric($limit) || (int) $limit < 1) {
            $this->components->error('--limit must be a positive number.');

            return self::FAILURE;
        }

        $type = null;
        if (is_string($typeOption) && $typeOption !== '') {
            $type = EntryType::tryFrom(strtolower($typeOption));

            if ($type === null) {
                $this->components->error('--type must be one of: '.implode(', ', array_map(static fn (EntryType $t): string => $t->value, EntryType::cases())).'.');

                return self::FAILURE;
            }
        }

        $pair = $this->releasePair($traces);
        if ($pair === false) {
            return self::FAILURE;
        }

        $limit = (int) $limit;
        $now = CarbonImmutable::now();
        $since = $now->subDays((int) $days);

        $this->summary($traces, $issues, $since, $now, $type);
        $this->topIssues($issues, $since, $limit);
        $this->topByImpact($issues, $since, $limit);
        $this->deploy($comparator, $pair, $type, $limit);
        $this->slowestEntries($traces, $since, $limit, $type);
        $this->topQueries($traces, $since, $limit);
        $this->bottlenecks($traces, $since);

        return self::SUCCESS;
    }

    private function summary(TraceRepository $traces, IssueRepository $issues, CarbonImmutable $since, CarbonImmutable $now, ?EntryType $type): void
    {
        $open = $issues->countBySeverity(IssueStatus::Open);
        $parts = [];
        foreach ([Severity::Critical, Severity::Warning, Severity::Info] as $severity) {
            $parts[] = ($open[$severity->value] ?? 0).' '.$severity->value;
        }

        $this->components->twoColumnDetail('<fg=green;options=bold>Sherlock report</>');
        $this->components->twoColumnDetail('Period', $since->format('Y-m-d H:i').' to '.$now->format('Y-m-d H:i T'));
        $this->components->twoColumnDetail('Traces'.($type === null ? '' : ' ('.$type->value.')'), number_format($traces->countSince($since, $type)));
        $this->components->twoColumnDetail('Open issues', number_format(array_sum($open)).' ('.implode(', ', $parts).')');
    }

    private function topIssues(IssueRepository $issues, CarbonImmutable $since, int $limit): void
    {
        $rows = array_map(static fn (IssueRecord $issue): array => [
            (string) $issue->id,
            $issue->severity->value,
            $issue->type->label(),
            self::limit($issue->title, self::NAME_CHARS),
            number_format($issue->occurrences),
            $issue->lastSeenAt->format('Y-m-d H:i'),
            $issue->aiStatus === null ? '-' : $issue->aiStatus->value,
        ], $issues->paginate(new IssueFilter(status: IssueStatus::Open, since: $since, sort: 'occurrences'), $limit)->items());

        $this->section('Top open issues (by occurrences)', ['ID', 'Severity', 'Type', 'Title', 'Occurrences', 'Last seen', 'AI'], $rows);
    }

    private function topByImpact(IssueRepository $issues, CarbonImmutable $since, int $limit): void
    {
        $impactful = array_filter($issues->topByImpact($since, $limit), static fn (IssueRecord $issue): bool => $issue->impactMs > 0);

        $rows = array_map(static fn (IssueRecord $issue): array => [
            (string) $issue->id,
            $issue->severity->value,
            $issue->type->label(),
            self::limit($issue->title, self::NAME_CHARS),
            self::human($issue->impactMs),
            number_format($issue->occurrences),
            $issue->status->value,
        ], array_values($impactful));

        $this->section('Top issues by impact (cumulative time cost)', ['ID', 'Severity', 'Type', 'Title', 'Impact', 'Occurrences', 'Status'], $rows);
    }

    /**
     * Resolves --release / --compare: [current, previous], null for "latest vs previous",
     * false on invalid input (error already printed).
     *
     * @return array{0: string, 1: string|null}|false|null
     */
    private function releasePair(TraceRepository $traces): array|false|null
    {
        $release = $this->option('release');
        $compare = $this->option('compare');
        $release = is_string($release) && trim($release) !== '' ? trim($release) : null;
        $compare = is_string($compare) && trim($compare) !== '' ? trim($compare) : null;

        if ($release !== null && $compare !== null) {
            $this->components->error('Use either --release or --compare, not both.');

            return false;
        }

        if ($compare !== null) {
            $parts = array_values(array_filter(array_map('trim', explode(',', $compare)), static fn (string $p): bool => $p !== ''));

            if (count($parts) !== 2 || $parts[0] === $parts[1]) {
                $this->components->error('--compare expects two different releases: --compare=after,before.');

                return false;
            }

            foreach ($parts as $part) {
                if ($traces->findRelease($part) === null) {
                    $this->components->error("Unknown release [{$part}].");

                    return false;
                }
            }

            return [$parts[0], $parts[1]];
        }

        if ($release === null) {
            return null;
        }

        if ($traces->findRelease($release) === null) {
            $this->components->error("Unknown release [{$release}].");

            return false;
        }

        $names = array_map(static fn (ReleaseRecord $r): string => $r->release, $traces->releases(self::RELEASE_LOOKUP));
        $index = array_search($release, $names, true);

        return [$release, $index === false ? null : ($names[$index + 1] ?? null)];
    }

    /**
     * @param  array{0: string, 1: string|null}|null  $pair
     */
    private function deploy(ReleaseComparator $comparator, ?array $pair, ?EntryType $type, int $limit): void
    {
        if ($pair !== null && $pair[1] === null) {
            $this->newLine();
            $this->line('<fg=green;options=bold>Deploy: '.$pair[0].'</>');
            $this->line('  <fg=gray>No release was recorded before '.$pair[0].': nothing to compare with.</>');

            return;
        }

        $comparison = $pair === null
            ? $comparator->compareLatest($type, max($limit, 50))
            : $comparator->compare($pair[0], (string) $pair[1], $type, max($limit, 50));

        if ($comparison === null) {
            $this->newLine();
            $this->line('<fg=green;options=bold>Latest deploy</>');
            $this->line('  <fg=gray>Fewer than two releases recorded. Set SHERLOCK_RELEASE (or deploy from git) and run "php artisan config:cache" on deploy.</>');

            return;
        }

        $this->newLine();
        $this->line('<fg=green;options=bold>'.($pair === null ? 'Latest deploy' : 'Deploy').': '.$comparison->current.' vs '.$comparison->previous.($type === null ? '' : ' ('.$type->value.'s)').'</>');
        $this->line('  '.($comparison->hasRegressions() ? '<fg=red>' : '<fg=green>').DeploySummary::headline($comparison).'</>');

        $changed = array_values(array_filter($comparison->entries, static fn (EntryDelta $d): bool => $d->status !== EntryDelta::UNCHANGED));
        $rows = array_map(static fn (EntryDelta $d): array => [
            $d->type->value,
            self::limit($d->name, self::NAME_CHARS),
            $d->status,
            $d->previous === null ? '-' : self::ms($d->previous->avgMs),
            $d->current === null ? '-' : self::ms($d->current->avgMs),
            self::signedPercent($d->deltaRatio),
            self::signedMs($d->deltaAvgDbMs),
            $d->deltaAvgQueries === null ? '-' : ($d->deltaAvgQueries > 0 ? '+' : '').number_format($d->deltaAvgQueries, 1),
            $d->current === null || $d->previous === null ? '-' : ($d->basis === EntryDelta::BASIS_ALL ? 'all (biased)' : 'sample'),
        ], array_slice($changed, 0, $limit));

        if ($rows === []) {
            $this->line('  <fg=gray>No entry got slower, faster, new or gone.</>');
        } else {
            $this->table(['Type', 'Name', 'Status', 'Before', 'After', 'Change', 'DB', 'Queries', 'Basis'], $rows);
        }

        if (array_filter($comparison->entries, static fn (EntryDelta $d): bool => $d->basis === EntryDelta::BASIS_ALL && $d->current !== null && $d->previous !== null) !== []) {
            $this->line('  <fg=gray>"all (biased)": too few sampled traces, averaged over every stored trace (in production: slow + sampled ones). Raise sample_rate for fair comparisons.</>');
        }

        $issueRows = [];
        foreach (['new' => $comparison->newIssues, 'regressed' => $comparison->regressedIssues, 'gone' => $comparison->goneIssues] as $label => $list) {
            foreach (array_slice($list, 0, $limit) as $issue) {
                $issueRows[] = [$label, (string) $issue->id, $issue->severity->value, $issue->type->label(), self::limit($issue->title, self::NAME_CHARS)];
            }
        }

        if ($issueRows !== []) {
            $this->table(['Change', 'ID', 'Severity', 'Type', 'Title'], $issueRows);
        }
    }

    private function slowestEntries(TraceRepository $traces, CarbonImmutable $since, int $limit, ?EntryType $type): void
    {
        $rows = array_map(static fn (EntryStat $stat): array => [
            $stat->type->value,
            self::limit($stat->name, self::NAME_CHARS),
            number_format($stat->count),
            self::ms($stat->avgMs),
            self::ms($stat->maxMs),
            self::ms($stat->avgDbMs),
            number_format($stat->avgQueries, 1),
        ], $traces->slowestEntries($since, $limit, $type));

        $this->section('Slowest entries (by average duration)', ['Type', 'Name', 'Count', 'Avg', 'Max', 'Avg DB', 'Avg queries'], $rows);
    }

    private function topQueries(TraceRepository $traces, CarbonImmutable $since, int $limit): void
    {
        $rows = array_map(static fn (QueryStat $stat): array => [
            self::limit(trim((string) preg_replace('/\s+/', ' ', $stat->normalizedSql)), self::SQL_CHARS),
            number_format($stat->count),
            self::ms($stat->totalMs),
            self::ms($stat->avgMs),
            self::ms($stat->maxMs),
        ], $traces->topQueries($since, 'total', $limit));

        $this->section('Top queries (by total time)', ['Normalized SQL', 'Count', 'Total', 'Avg', 'Max'], $rows);
    }

    private function bottlenecks(TraceRepository $traces, CarbonImmutable $since): void
    {
        $distribution = $traces->bottleneckDistribution($since);
        $total = max(1, array_sum($distribution));
        $rows = [];

        foreach ($distribution as $value => $count) {
            $rows[] = [
                Bottleneck::tryFrom($value)?->label() ?? $value,
                number_format($count),
                round($count / $total * 100, 1).'%',
            ];
        }

        $this->section('Bottleneck distribution (verdicts)', ['Bottleneck', 'Traces', 'Share'], $rows);
    }

    /**
     * @param  list<string>  $headers
     * @param  array<int, list<string>>  $rows
     */
    private function section(string $title, array $headers, array $rows): void
    {
        $this->newLine();
        $this->line("<fg=green;options=bold>{$title}</>");

        if ($rows === []) {
            $this->line('  <fg=gray>No data for this period.</>');

            return;
        }

        $this->table($headers, $rows);
    }

    private static function ms(float $ms): string
    {
        return $ms >= 1000 ? number_format($ms / 1000, 2).' s' : number_format($ms, $ms < 10 ? 1 : 0).' ms';
    }

    private static function human(float $ms): string
    {
        return match (true) {
            $ms >= 3_600_000 => number_format($ms / 3_600_000, 1).' h',
            $ms >= 60_000 => number_format($ms / 60_000, 1).' min',
            default => self::ms($ms),
        };
    }

    private static function signedMs(?float $ms): string
    {
        return $ms === null ? '-' : ($ms > 0 ? '+' : ($ms < 0 ? '-' : '')).self::ms(abs($ms));
    }

    private static function signedPercent(?float $ratio): string
    {
        return $ratio === null ? '-' : ($ratio > 0 ? '+' : '').round($ratio * 100, 1).'%';
    }

    private static function limit(string $text, int $chars): string
    {
        return mb_strlen($text) > $chars ? rtrim(mb_substr($text, 0, $chars - 1)).'…' : $text;
    }
}
