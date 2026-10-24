<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Http\Controllers;

use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Contracts\TraceRepository;
use Anabil\Sherlock\Enums\Bottleneck;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\IssueStatus;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Http\Support\Period;
use Anabil\Sherlock\Http\Support\Views;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Overview for a period: trace counts, open issues by severity, bottleneck split,
 * slowest entries, top queries and recent new / regressed issues.
 */
final class OverviewController
{
    private const LIST_LIMIT = 8;

    public function __construct(
        private readonly TraceRepository $traces,
        private readonly IssueRepository $issues,
        private readonly Views $views,
    ) {}

    public function __invoke(Request $request): View
    {
        $period = Period::fromRequest($request);

        $counts = [];
        foreach (EntryType::cases() as $type) {
            $counts[$type->value] = $this->traces->countSince($period->since, $type);
        }

        $bySeverity = $this->issues->countBySeverity(IssueStatus::Open);
        $severities = [];
        foreach ([Severity::Critical, Severity::Warning, Severity::Info] as $severity) {
            $severities[] = ['severity' => $severity, 'count' => $bySeverity[$severity->value] ?? 0];
        }

        return $this->views->make('sherlock::dashboard.overview', [
            'period' => $period,
            'traceCounts' => $counts,
            'totalTraces' => array_sum($counts),
            'severities' => $severities,
            'openIssues' => array_sum($bySeverity),
            'bottlenecks' => $this->bottlenecks($period),
            'slowest' => $this->traces->slowestEntries($period->since, self::LIST_LIMIT),
            'topQueries' => $this->traces->topQueries($period->since, 'total', self::LIST_LIMIT),
            'newIssues' => $this->issues->newSince($period->since, self::LIST_LIMIT),
            'regressedIssues' => $this->issues->regressedSince($period->since, self::LIST_LIMIT),
        ]);
    }

    /**
     * @return list<array{bottleneck: Bottleneck, count: int, percent: float}>
     */
    private function bottlenecks(Period $period): array
    {
        $distribution = $this->traces->bottleneckDistribution($period->since);
        $total = array_sum($distribution);
        $rows = [];

        foreach ($distribution as $value => $count) {
            $bottleneck = Bottleneck::tryFrom($value) ?? Bottleneck::Unknown;
            $rows[] = [
                'bottleneck' => $bottleneck,
                'count' => $count,
                'percent' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
            ];
        }

        return $rows;
    }
}
