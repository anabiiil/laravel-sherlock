<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Digest;

use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Contracts\TraceRepository;
use Anabil\Sherlock\Data\EntryDelta;
use Anabil\Sherlock\Data\EntryStat;
use Anabil\Sherlock\Data\IssueRecord;
use Anabil\Sherlock\Data\NotificationMessage;
use Anabil\Sherlock\Data\QueryStat;
use Anabil\Sherlock\Data\ReleaseComparison;
use Anabil\Sherlock\Enums\Bottleneck;
use Anabil\Sherlock\Enums\IssueStatus;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Releases\ReleaseComparator;
use DateTimeImmutable;

/**
 * Builds the periodic digest (Builder) from stored data only: repositories in, a
 * channel-agnostic NotificationMessage out. "Since last deploy" (ReleaseComparator) only
 * appears once at least two releases were recorded. Never calls the AI: issues carry the analysis
 * stored when they were first analyzed, and only an excerpt of it is included.
 */
final class DigestBuilder
{
    /** Fingerprint marking digest messages, so channels / the dispatcher can tell them apart. */
    public const FINGERPRINT = 'sherlock-digest';

    private const SQL_CHARS = 140;

    private const EXCERPT_CHARS = 160;

    private const TITLE_CHARS = 120;

    /** Issue lists are over-fetched so dropping ignored issues still fills "limit" rows. */
    private const OVERFETCH = 3;

    /** Lines of the "Since last deploy" section (slower entries / new issues each). */
    private const DEPLOY_LINES = 5;

    private readonly ReleaseComparator $comparator;

    public function __construct(
        private readonly TraceRepository $traces,
        private readonly IssueRepository $issues,
        private readonly int $limit = 10,
        private readonly ?string $dashboardUrl = null,
        private readonly string $appName = 'Laravel',
        ?ReleaseComparator $comparator = null,
    ) {
        $this->comparator = $comparator ?? new ReleaseComparator($traces, $issues);
    }

    public function build(DateTimeImmutable $since, DateTimeImmutable $until): DigestReport
    {
        $limit = max(1, $this->limit);

        return new DigestReport(
            $since,
            $until,
            $this->traces->countSince($since),
            $this->issues->countBySeverity(IssueStatus::Open),
            $this->withoutIgnored($this->issues->newSince($since, $limit * self::OVERFETCH), $limit),
            $this->withoutIgnored($this->issues->regressedSince($since, $limit * self::OVERFETCH), $limit),
            $this->withoutIgnored($this->issues->mostFrequent($since, $limit * self::OVERFETCH), $limit),
            $this->traces->slowestEntries($since, $limit),
            $this->traces->topQueries($since, 'total', $limit),
            $this->traces->bottleneckDistribution($since),
            $this->impactful($this->issues->topByImpact($since, $limit * self::OVERFETCH), $limit),
            $this->comparator->compareLatest(null, max(self::DEPLOY_LINES * 4, $limit)),
        );
    }

    /**
     * @param  list<IssueRecord>  $issues
     * @return list<IssueRecord>
     */
    private function impactful(array $issues, int $limit): array
    {
        return array_slice(array_values(array_filter(
            $this->withoutIgnored($issues, count($issues)),
            static fn (IssueRecord $issue): bool => $issue->impactMs > 0,
        )), 0, $limit);
    }

    /**
     * Ignored issues were silenced on purpose; they do not belong in a digest.
     *
     * @param  list<IssueRecord>  $issues
     * @return list<IssueRecord>
     */
    private function withoutIgnored(array $issues, int $limit): array
    {
        $kept = array_filter($issues, static fn (IssueRecord $issue): bool => $issue->status !== IssueStatus::Ignored);

        return array_slice(array_values($kept), 0, $limit);
    }

    public function message(DigestReport $report): NotificationMessage
    {
        $fields = [
            'Period' => $this->period($report),
            'Traces recorded' => number_format($report->traceCount),
            'Open issues' => $this->openIssues($report),
        ];

        $sections = [
            'New issues' => array_map(fn (IssueRecord $i): string => $this->issueLine($i), $report->newIssues),
            'Regressed issues' => array_map(fn (IssueRecord $i): string => $this->issueLine($i), $report->regressedIssues),
            'Most frequent issues' => array_map(fn (IssueRecord $i): string => $this->issueLine($i, true), $report->frequentIssues),
            'Top by impact' => array_map(fn (IssueRecord $i): string => $this->impactLine($i), $report->impactIssues),
            'Since last deploy' => $report->deploy === null ? [] : $this->deployLines($report->deploy),
            'Slowest entries' => array_map(fn (EntryStat $s): string => $this->entryLine($s), $report->slowestEntries),
            'Top queries' => array_map(fn (QueryStat $s): string => $this->queryLine($s), $report->topQueries),
        ];

        foreach ($sections as $label => $lines) {
            if ($lines !== []) {
                $fields[$label] = implode("\n", $lines);
            }
        }

        if ($report->bottlenecks !== []) {
            $fields['Bottlenecks'] = $this->bottlenecks($report->bottlenecks);
        }

        return new NotificationMessage(
            title: sprintf('Digest: %s', $this->appName),
            severity: $report->severity(),
            summary: $this->summary($report),
            fields: $fields,
            url: $this->dashboardUrl,
            footer: 'Periodic digest from stored data. No new AI calls were made.',
            fingerprint: self::FINGERPRINT,
            kind: NotificationMessage::KIND_DIGEST,
        );
    }

    /**
     * Short plain-text excerpt of a stored AI analysis, preferring its "Fix" section.
     */
    public static function excerpt(string $analysis, int $chars = self::EXCERPT_CHARS): string
    {
        $text = $analysis;

        if (preg_match('/(?:^|\n)[#*\s]*fix[*:\s]*\n(.+?)(?=\n[#*\s]*(?:code example|expected impact|root cause)\b|$)/is', $analysis, $match) === 1) {
            $text = $match[1];
        }

        $text = (string) preg_replace('/```.*?(```|$)/s', ' ', $text);
        $text = (string) preg_replace('/[#*`>_]+/', '', $text);

        return self::limit(trim((string) preg_replace('/\s+/', ' ', $text)), $chars);
    }

    private function summary(DigestReport $report): string
    {
        if ($report->isQuiet()) {
            return 'All quiet: no traces were recorded and no issues are open.';
        }

        return sprintf(
            '%s traces recorded, %d new and %d regressed issues; %d open issues in total.',
            number_format($report->traceCount),
            count($report->newIssues),
            count($report->regressedIssues),
            $report->openIssues(),
        );
    }

    private function period(DigestReport $report): string
    {
        return sprintf('%s to %s', $report->since->format('Y-m-d H:i'), $report->until->format('Y-m-d H:i T'));
    }

    private function openIssues(DigestReport $report): string
    {
        if ($report->openIssues() === 0) {
            return 'none';
        }

        $parts = [];
        foreach ([Severity::Critical, Severity::Warning, Severity::Info] as $severity) {
            $count = $report->openBySeverity[$severity->value] ?? 0;
            if ($count > 0) {
                $parts[] = $count.' '.$severity->value;
            }
        }

        return sprintf('%d (%s)', $report->openIssues(), implode(', ', $parts));
    }

    private function issueLine(IssueRecord $issue, bool $withAi = false): string
    {
        $details = [$issue->type->label(), number_format($issue->occurrences).'x'];

        if ($issue->status !== IssueStatus::Open) {
            $details[] = $issue->status->value;
        }

        $location = $this->location($issue);
        if ($location !== null) {
            $details[] = $location;
        }

        $line = sprintf(
            '• [%s] %s (%s)',
            strtoupper($issue->severity->value),
            self::limit($issue->title, self::TITLE_CHARS),
            implode(' · ', $details),
        );

        if ($withAi && $issue->hasAiAnalysis()) {
            $line .= "\n    AI: ".self::excerpt((string) $issue->aiAnalysis);
        }

        return $line;
    }

    private function impactLine(IssueRecord $issue): string
    {
        return sprintf(
            '• %s total · [%s] %s (%s · %sx)',
            self::human($issue->impactMs),
            strtoupper($issue->severity->value),
            self::limit($issue->title, self::TITLE_CHARS),
            $issue->type->label(),
            number_format($issue->occurrences),
        );
    }

    /**
     * Headline, then the slowest regressions and the issues the deploy introduced.
     *
     * @return list<string>
     */
    private function deployLines(ReleaseComparison $deploy): array
    {
        $lines = [DeploySummary::headline($deploy).' (vs '.$deploy->previous.')'];

        foreach (array_slice($deploy->slower(), 0, self::DEPLOY_LINES) as $delta) {
            $lines[] = sprintf(
                '• slower: %s (%s) %s -> %s (%s)%s',
                self::limit($delta->name, self::TITLE_CHARS),
                $delta->type->value,
                self::ms($delta->previous->avgMs ?? 0.0),
                self::ms($delta->current->avgMs ?? 0.0),
                $delta->deltaRatio === null ? '+'.self::ms((float) $delta->deltaAvgMs) : '+'.(int) round($delta->deltaRatio * 100).'%',
                $delta->basis === EntryDelta::BASIS_ALL ? ' [biased: all stored traces]' : '',
            );
        }

        $issues = [
            ...array_map(static fn (IssueRecord $i): array => ['new issue', $i], $deploy->newIssues),
            ...array_map(static fn (IssueRecord $i): array => ['regressed', $i], $deploy->regressedIssues),
        ];

        foreach (array_slice($issues, 0, self::DEPLOY_LINES) as [$label, $issue]) {
            $lines[] = sprintf(
                '• %s: [%s] %s (%s)',
                $label,
                strtoupper($issue->severity->value),
                self::limit($issue->title, self::TITLE_CHARS),
                $issue->type->label(),
            );
        }

        return $lines;
    }

    private function location(IssueRecord $issue): ?string
    {
        $location = $issue->context['location'] ?? null;

        if (is_array($location) && is_string($location['file'] ?? null) && $location['file'] !== '') {
            $line = $location['line'] ?? null;

            return $location['file'].(is_int($line) ? ':'.$line : '');
        }

        $entry = $issue->context['entry'] ?? null;

        return is_array($entry) && is_string($entry['name'] ?? null) && $entry['name'] !== '' ? $entry['name'] : null;
    }

    private function entryLine(EntryStat $stat): string
    {
        return sprintf(
            '• %s (%s): avg %s · max %s · %sx · %s queries avg',
            self::limit($stat->name, self::TITLE_CHARS),
            $stat->type->value,
            self::ms($stat->avgMs),
            self::ms($stat->maxMs),
            number_format($stat->count),
            number_format($stat->avgQueries, 1),
        );
    }

    private function queryLine(QueryStat $stat): string
    {
        return sprintf(
            '• %s total · %sx · avg %s: %s',
            self::ms($stat->totalMs),
            number_format($stat->count),
            self::ms($stat->avgMs),
            self::limit(trim((string) preg_replace('/\s+/', ' ', $stat->normalizedSql)), self::SQL_CHARS),
        );
    }

    /**
     * @param  array<string, int>  $distribution
     */
    private function bottlenecks(array $distribution): string
    {
        $total = max(1, array_sum($distribution));
        $parts = [];

        foreach ($distribution as $value => $count) {
            $label = Bottleneck::tryFrom($value)?->label() ?? $value;
            $parts[] = sprintf('%s %d%% (%s)', $label, (int) round($count / $total * 100), number_format($count));
        }

        return implode(' · ', $parts);
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

    private static function limit(string $text, int $chars): string
    {
        return mb_strlen($text) > $chars ? rtrim(mb_substr($text, 0, $chars - 1)).'…' : $text;
    }
}
