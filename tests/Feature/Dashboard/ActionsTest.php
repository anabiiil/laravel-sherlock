<?php

declare(strict_types=1);

use Anabil\Tracing\Contracts\IssueRepository;
use Anabil\Tracing\Enums\AiStatus;
use Anabil\Tracing\Enums\IssueStatus;
use Anabil\Tracing\Jobs\AnalyzeIssueJob;
use Anabil\Tracing\Jobs\NotifyIssueJob;
use Anabil\Tracing\Tests\Feature\Dashboard\DashboardFixtures as D;
use Illuminate\Support\Facades\Bus;

beforeEach(fn () => D::allowAccess());

function dashboardIssueStatus(int|string $id): ?IssueStatus
{
    return app(IssueRepository::class)->find($id)?->status;
}

it('resolves, ignores and reopens an issue and redirects back with a flash message', function (): void {
    $issue = D::issueWith('fp-actions', 'Action issue');
    $show = '/tracing/issues/'.$issue->id;

    $this->post($show.'/resolve')->assertRedirect($show)->assertSessionHas('tracing.status', 'Issue marked as resolved.');
    expect(dashboardIssueStatus($issue->id))->toBe(IssueStatus::Resolved)
        ->and(app(IssueRepository::class)->find($issue->id)?->resolvedAt)->not->toBeNull();

    $this->post($show.'/ignore')->assertRedirect($show)->assertSessionHas('tracing.status');
    expect(dashboardIssueStatus($issue->id))->toBe(IssueStatus::Ignored);

    $this->post($show.'/reopen')->assertRedirect($show)->assertSessionHas('tracing.status', 'Issue reopened.');
    expect(dashboardIssueStatus($issue->id))->toBe(IssueStatus::Open);

    $this->followingRedirects()->post($show.'/resolve')->assertOk()->assertSee('Issue marked as resolved.')->assertSee('Reopen');
});

it('queues a fresh AI analysis without a notification', function (): void {
    Bus::fake();
    $issue = D::issueWith('fp-ai', 'AI issue');
    app(IssueRepository::class)->updateAiStatus($issue->id, AiStatus::Failed);

    $this->post('/tracing/issues/'.$issue->id.'/reanalyze')
        ->assertRedirect('/tracing/issues/'.$issue->id)
        ->assertSessionHas('tracing.status');

    Bus::assertDispatched(AnalyzeIssueJob::class, fn (AnalyzeIssueJob $job): bool => $job->issueId === $issue->id && $job->fresh === true);
    Bus::assertNotDispatched(NotifyIssueJob::class);
    expect(app(IssueRepository::class)->find($issue->id)?->aiStatus)->toBe(AiStatus::Pending);
});

it('returns 404 for actions on unknown issues and rejects GET on actions', function (): void {
    Bus::fake();

    $this->post('/tracing/issues/999999/resolve')->assertNotFound();
    $this->post('/tracing/issues/999999/reanalyze')->assertNotFound();
    $this->get('/tracing/issues/1/resolve')->assertStatus(405);

    Bus::assertNothingDispatched();
});

it('protects the actions with CSRF', function (): void {
    $issue = D::issueWith('fp-csrf', 'CSRF issue');

    // The test kernel skips CSRF verification; check the forms carry the token and the
    // routes sit in the "web" group (which verifies it in a real request).
    $this->get('/tracing/issues/'.$issue->id)->assertOk()->assertSee('name="_token"', false);

    $route = app('router')->getRoutes()->getByName('tracing.issues.resolve');
    expect($route?->methods())->toBe(['POST'])
        ->and($route?->gatherMiddleware())->toContain('web');
});

it('enforces the CSRF token outside the test kernel bypass', function (): void {
    $issue = D::issueWith('fp-csrf-real', 'CSRF real issue');

    // VerifyCsrfToken skips the check while app('env') === 'testing'; lift that for one request.
    $this->app->instance('env', 'production');

    try {
        $this->post('/tracing/issues/'.$issue->id.'/resolve')->assertStatus(419);
    } finally {
        $this->app->instance('env', 'testing');
    }

    expect(dashboardIssueStatus($issue->id))->toBe(IssueStatus::Open);
});
