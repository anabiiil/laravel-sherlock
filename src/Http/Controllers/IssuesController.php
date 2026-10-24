<?php

declare(strict_types=1);

namespace Anabil\Tracing\Http\Controllers;

use Anabil\Tracing\Contracts\IssueRepository;
use Anabil\Tracing\Contracts\IssueService;
use Anabil\Tracing\Contracts\TraceRepository;
use Anabil\Tracing\Data\IssueRecord;
use Anabil\Tracing\Data\TraceFilter;
use Anabil\Tracing\Enums\AiStatus;
use Anabil\Tracing\Enums\IssueStatus;
use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Enums\Severity;
use Anabil\Tracing\Http\Filters\Input;
use Anabil\Tracing\Http\Filters\IssueFilters;
use Anabil\Tracing\Http\Presenters\IssuePresenter;
use Anabil\Tracing\Http\Support\Views;
use Anabil\Tracing\Jobs\AnalyzeIssueJob;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Issues list (filters + pagination), detail page and the status / re-analyze actions.
 */
final class IssuesController
{
    private const EXAMPLE_TRACES = 10;

    public function __construct(
        private readonly IssueRepository $issues,
        private readonly TraceRepository $traces,
        private readonly IssueService $service,
        private readonly Bus $bus,
        private readonly Config $config,
        private readonly Views $views,
        private readonly Redirector $redirect,
    ) {}

    public function index(Request $request): View
    {
        $filters = IssueFilters::fromRequest($request);
        $perPage = $this->config->get('tracing.dashboard.per_page', 25);

        $issues = $this->issues
            ->paginate($filters->toFilter(), is_numeric($perPage) ? max(1, (int) $perPage) : 25, Input::page($request))
            ->appends($filters->query());

        return $this->views->make('tracing::dashboard.issues.index', [
            'issues' => $issues,
            'filters' => $filters,
            'types' => IssueType::cases(),
            'severities' => Severity::cases(),
            'statuses' => IssueStatus::cases(),
            'sorts' => IssueFilters::SORTS,
        ]);
    }

    public function show(string $issue): View
    {
        $record = $this->findOrFail($issue);

        return $this->views->make('tracing::dashboard.issues.show', [
            'issue' => $record,
            'presenter' => new IssuePresenter($record),
            'traces' => $this->traces->paginate(new TraceFilter(issueId: $record->id), self::EXAMPLE_TRACES, 1),
            'aiEnabled' => (bool) $this->config->get('tracing.ai.enabled', false),
        ]);
    }

    public function resolve(string $issue): RedirectResponse
    {
        return $this->changeStatus($issue, IssueStatus::Resolved, 'Issue marked as resolved.');
    }

    public function ignore(string $issue): RedirectResponse
    {
        return $this->changeStatus($issue, IssueStatus::Ignored, 'Issue ignored. New occurrences are only counted.');
    }

    public function reopen(string $issue): RedirectResponse
    {
        return $this->changeStatus($issue, IssueStatus::Open, 'Issue reopened.');
    }

    /**
     * Queue a fresh AI analysis (no notification is sent for a manual re-analysis).
     */
    public function reanalyze(string $issue): RedirectResponse
    {
        $record = $this->findOrFail($issue);

        $this->issues->updateAiStatus($record->id, AiStatus::Pending);
        $this->bus->dispatch(new AnalyzeIssueJob($record->id, fresh: true));

        return $this->backTo($record, 'Re-analysis queued. Refresh in a moment to see the result.');
    }

    private function changeStatus(string $issue, IssueStatus $status, string $message): RedirectResponse
    {
        $record = $this->findOrFail($issue);

        $this->service->changeStatus($record->id, $status);

        return $this->backTo($record, $message);
    }

    /**
     * Always back to the issue page (never to a Referer-provided URL).
     */
    private function backTo(IssueRecord $record, string $message): RedirectResponse
    {
        return $this->redirect
            ->route('tracing.issues.show', ['issue' => $record->id])
            ->with('tracing.status', $message);
    }

    private function findOrFail(string $issue): IssueRecord
    {
        return $this->issues->find($issue) ?? throw new NotFoundHttpException('Issue not found.');
    }
}
