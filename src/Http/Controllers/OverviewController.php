<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Http\Controllers;

use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Contracts\TraceRepository;
use Anabil\Sherlock\Data\IssueRecord;
use Anabil\Sherlock\Enums\Bottleneck;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\IssueStatus;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Http\Support\Period;
use Anabil\Sherlock\Http\Support\Views;
use Anabil\Sherlock\Releases\ReleaseComparator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Overview for a period: trace counts, open issues by severity, bottleneck split,
 * slowest entries, top queries, recent new / regressed issues, the issues costing the most
 * time (cumulative impact) and a summary of the latest deploy (latest vs previous release).
 */
final class OverviewController
{
    private const LIST_LIMIT = 8;

    private const DEPLOY_LIMIT = 50;

    public function __construct(
        private readonly TraceRepository $traces,
        private readonly IssueRepository $issues,
        private readonly ReleaseComparator $comparator,
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
            'impactIssues' => array_values(array_filter(
                $this->issues->topByImpact($period->since, self::LIST_LIMIT),
                static fn (IssueRecord $issue): bool => $issue->impactMs > 0,
            )),
            'deploy' => $this->comparator->compareLatest(null, self::DEPLOY_LIMIT),
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
