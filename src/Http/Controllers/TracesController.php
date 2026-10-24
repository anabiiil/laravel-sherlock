<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Http\Controllers;

use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Contracts\TraceRepository;
use Anabil\Sherlock\Data\IssueRecord;
use Anabil\Sherlock\Enums\Bottleneck;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Http\Filters\Input;
use Anabil\Sherlock\Http\Filters\TraceFilters;
use Anabil\Sherlock\Http\Presenters\TracePresenter;
use Anabil\Sherlock\Http\Support\Views;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Traces list (filters + pagination) and the trace detail page.
 */
final class TracesController
{
    private const RELEASE_OPTIONS = 50;

    public function __construct(
        private readonly TraceRepository $traces,
        private readonly IssueRepository $issues,
        private readonly Config $config,
        private readonly Views $views,
    ) {}

    public function index(Request $request): View
    {
        $filters = TraceFilters::fromRequest($request);
        $perPage = $this->config->get('sherlock.dashboard.per_page', 25);

        $traces = $this->traces
            ->paginate($filters->toFilter(), is_numeric($perPage) ? max(1, (int) $perPage) : 25, Input::page($request))
            ->appends($filters->query());

        return $this->views->make('sherlock::dashboard.traces.index', [
            'traces' => $traces,
            'filters' => $filters,
            'issue' => $filters->issueId === null ? null : $this->issues->find($filters->issueId),
            'types' => EntryType::cases(),
            'bottlenecks' => Bottleneck::cases(),
            'sorts' => TraceFilters::SORTS,
            'releases' => $this->traces->releases(self::RELEASE_OPTIONS),
        ]);
    }

    public function show(string $trace): View
    {
        $record = $this->traces->find($trace) ?? throw new NotFoundHttpException('Trace not found.');
        $threshold = $this->config->get('sherlock.thresholds.n_plus_one.warning_count', 5);

        // Link findings to their issues: one indexed lookup per distinct finding (capped).
        $issues = [];
        foreach (TracePresenter::findingFingerprints($record) as $fingerprint) {
            $issue = $this->issues->findByFingerprint($fingerprint);
            if ($issue instanceof IssueRecord) {
                $issues[$fingerprint] = $issue;
            }
        }

        return $this->views->make('sherlock::dashboard.traces.show', [
            'trace' => $record,
            'presenter' => new TracePresenter(
                $record,
                $this->traces->queries($record->id),
                is_numeric($threshold) ? max(2, (int) $threshold) : 5,
                $issues,
            ),
        ]);
    }
}
