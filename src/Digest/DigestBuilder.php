<?php

declare(strict_types=1);

namespace Anabil\Tracing\Digest;

use Anabil\Tracing\Contracts\IssueRepository;
use Anabil\Tracing\Contracts\TraceRepository;
use Anabil\Tracing\Data\EntryStat;
use Anabil\Tracing\Data\IssueRecord;
use Anabil\Tracing\Data\NotificationMessage;
use Anabil\Tracing\Data\QueryStat;
use Anabil\Tracing\Enums\Bottleneck;
use Anabil\Tracing\Enums\IssueStatus;
use Anabil\Tracing\Enums\Severity;
use DateTimeImmutable;

/**
 * Builds the periodic digest (Builder) from stored data only: repositories in, a
 * channel-agnostic NotificationMessage out. Never calls the AI: issues carry the analysis
 * stored when they were first analyzed, and only an excerpt of it is included.
 */
final class DigestBuilder
{
    /** Fingerprint marking digest messages, so channels / the dispatcher can tell them apart. */
    public const FINGERPRINT = 'tracing-digest';

    private const SQL_CHARS = 140;

    private const EXCERPT_CHARS = 160;

    private const TITLE_CHARS = 120;

    /** Issue lists are over-fetched so dropping ignored issues still fills "limit" rows. */
    private const OVERFETCH = 3;

    public function __construct(
        private readonly TraceRepository $traces,
        private readonly IssueRepository $issues,
        private readonly int $limit = 10,
        private readonly ?string $dashboardUrl = null,
        private readonly string $appName = 'Laravel',
    ) {}

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
        );
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
            title: sprintf('Tracing digest: %s', $this->appName),
            severity: $report->severity(),
            summary: $this->summary($report),
            fields: $fields,
            url: $this->dashboardUrl,
            footer: 'Periodic digest from stored data. No new AI calls were made.',
            fingerprint: self::FINGERPRINT,
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

    private static function limit(string $text, int $chars): string
    {
        return mb_strlen($text) > $chars ? rtrim(mb_substr($text, 0, $chars - 1)).'…' : $text;
    }
}
