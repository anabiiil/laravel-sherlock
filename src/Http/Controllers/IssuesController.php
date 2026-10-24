<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Http\Controllers;

use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Contracts\IssueService;
use Anabil\Sherlock\Contracts\TraceRepository;
use Anabil\Sherlock\Data\IssueRecord;
use Anabil\Sherlock\Data\TraceFilter;
use Anabil\Sherlock\Enums\AiStatus;
use Anabil\Sherlock\Enums\IssueStatus;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Http\Filters\Input;
use Anabil\Sherlock\Http\Filters\IssueFilters;
use Anabil\Sherlock\Http\Presenters\IssuePresenter;
use Anabil\Sherlock\Http\Support\Views;
use Anabil\Sherlock\Jobs\AnalyzeIssueJob;
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

    private const RELEASE_OPTIONS = 50;

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
        $perPage = $this->config->get('sherlock.dashboard.per_page', 25);

        $issues = $this->issues
            ->paginate($filters->toFilter(), is_numeric($perPage) ? max(1, (int) $perPage) : 25, Input::page($request))
            ->appends($filters->query());

        return $this->views->make('sherlock::dashboard.issues.index', [
            'issues' => $issues,
            'filters' => $filters,
            'types' => IssueType::cases(),
            'severities' => Severity::cases(),
            'statuses' => IssueStatus::cases(),
            'sorts' => IssueFilters::SORTS,
            'releases' => $this->traces->releases(self::RELEASE_OPTIONS),
        ]);
    }

    public function show(string $issue): View
    {
        $record = $this->findOrFail($issue);

        return $this->views->make('sherlock::dashboard.issues.show', [
            'issue' => $record,
            'presenter' => new IssuePresenter($record),
            'traces' => $this->traces->paginate(new TraceFilter(issueId: $record->id), self::EXAMPLE_TRACES, 1),
            'aiEnabled' => (bool) $this->config->get('sherlock.ai.enabled', false),
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
            ->route('sherlock.issues.show', ['issue' => $record->id])
            ->with('sherlock.status', $message);
    }

    private function findOrFail(string $issue): IssueRecord
    {
        return $this->issues->find($issue) ?? throw new NotFoundHttpException('Issue not found.');
    }
}
