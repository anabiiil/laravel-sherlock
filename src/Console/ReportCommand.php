<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Console;

use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Contracts\TraceRepository;
use Anabil\Sherlock\Data\EntryStat;
use Anabil\Sherlock\Data\IssueFilter;
use Anabil\Sherlock\Data\IssueRecord;
use Anabil\Sherlock\Data\QueryStat;
use Anabil\Sherlock\Enums\Bottleneck;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\IssueStatus;
use Anabil\Sherlock\Enums\Severity;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Terminal summary of the last N days from stored data: top open issues, slowest entries,
 * top query fingerprints and the bottleneck distribution. --type filters the trace count
 * and the slowest entries by unit type (request | job | command).
 */
final class ReportCommand extends Command
{
    private const SQL_CHARS = 90;

    private const NAME_CHARS = 60;

    /** @var string */
    protected $signature = 'sherlock:report
        {--days=7 : Period to cover, in days}
        {--type= : Only this unit type for traces / slowest entries: request, job or command}
        {--limit=10 : Rows per table}';

    /** @var string */
    protected $description = 'Show a terminal summary: top issues, slowest routes / jobs / commands, top queries and bottlenecks';

    public function handle(TraceRepository $traces, IssueRepository $issues): int
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

        $limit = (int) $limit;
        $now = CarbonImmutable::now();
        $since = $now->subDays((int) $days);

        $this->summary($traces, $issues, $since, $now, $type);
        $this->topIssues($issues, $since, $limit);
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

    private static function limit(string $text, int $chars): string
    {
        return mb_strlen($text) > $chars ? rtrim(mb_substr($text, 0, $chars - 1)).'…' : $text;
    }
}
