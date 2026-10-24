<?php

declare(strict_types=1);

namespace Anabil\Tracing\Http\Controllers;

use Anabil\Tracing\Contracts\Fingerprinter;
use Anabil\Tracing\Contracts\IssueRepository;
use Anabil\Tracing\Contracts\TraceRepository;
use Anabil\Tracing\Data\IssueRecord;
use Anabil\Tracing\Data\QueryStat;
use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Http\Filters\Input;
use Anabil\Tracing\Http\Support\Period;
use Anabil\Tracing\Http\Support\SqlHighlighter;
use Anabil\Tracing\Http\Support\Views;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;

/**
 * Top query fingerprints of a period, ordered by total time, executions or average time.
 */
final class QueriesController
{
    /** @var array<string, string> */
    public const ORDERS = [
        'total' => 'Total time',
        'count' => 'Executions',
        'avg' => 'Average time',
    ];

    private const LIMIT = 50;

    public function __construct(
        private readonly TraceRepository $traces,
        private readonly IssueRepository $issues,
        private readonly Fingerprinter $fingerprinter,
        private readonly Views $views,
    ) {}

    public function __invoke(Request $request): View
    {
        $period = Period::fromRequest($request);
        $order = Input::string($request, 'order');
        $order = isset(self::ORDERS[$order]) ? $order : 'total';

        $stats = $this->traces->topQueries($period->since, $order, self::LIMIT);

        return $this->views->make('tracing::dashboard.queries.index', [
            'period' => $period,
            'order' => $order,
            'orders' => self::ORDERS,
            'rows' => array_map($this->row(...), $stats),
        ]);
    }

    /**
     * The missing-index issue of a fingerprint is keyed by the query fingerprint alone, so it
     * can be linked directly (other query issues also depend on location / entry).
     *
     * @return array{stat: QueryStat, sql: HtmlString, issue: IssueRecord|null}
     */
    private function row(QueryStat $stat): array
    {
        return [
            'stat' => $stat,
            'sql' => SqlHighlighter::highlight($stat->normalizedSql, false),
            'issue' => $this->issues->findByFingerprint($this->fingerprinter->issue(IssueType::MissingIndex, $stat->fingerprint)),
        ];
    }
}
