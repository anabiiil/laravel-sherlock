<?php

declare(strict_types=1);

use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Enums\AiStatus;
use Anabil\Sherlock\Enums\IssueStatus;
use Anabil\Sherlock\Jobs\AnalyzeIssueJob;
use Anabil\Sherlock\Jobs\NotifyIssueJob;
use Anabil\Sherlock\Tests\Feature\Dashboard\DashboardFixtures as D;
use Illuminate\Support\Facades\Bus;

beforeEach(fn () => D::allowAccess());

function dashboardIssueStatus(int|string $id): ?IssueStatus
{
    return app(IssueRepository::class)->find($id)?->status;
}

it('resolves, ignores and reopens an issue and redirects back with a flash message', function (): void {
    $issue = D::issueWith('fp-actions', 'Action issue');
    $show = '/sherlock/issues/'.$issue->id;

    $this->post($show.'/resolve')->assertRedirect($show)->assertSessionHas('sherlock.status', 'Issue marked as resolved.');
    expect(dashboardIssueStatus($issue->id))->toBe(IssueStatus::Resolved)
        ->and(app(IssueRepository::class)->find($issue->id)?->resolvedAt)->not->toBeNull();

    $this->post($show.'/ignore')->assertRedirect($show)->assertSessionHas('sherlock.status');
    expect(dashboardIssueStatus($issue->id))->toBe(IssueStatus::Ignored);

    $this->post($show.'/reopen')->assertRedirect($show)->assertSessionHas('sherlock.status', 'Issue reopened.');
    expect(dashboardIssueStatus($issue->id))->toBe(IssueStatus::Open);

    $this->followingRedirects()->post($show.'/resolve')->assertOk()->assertSee('Issue marked as resolved.')->assertSee('Reopen');
});

it('queues a fresh AI analysis without a notification', function (): void {
    Bus::fake();
    $issue = D::issueWith('fp-ai', 'AI issue');
    app(IssueRepository::class)->updateAiStatus($issue->id, AiStatus::Failed);

    $this->post('/sherlock/issues/'.$issue->id.'/reanalyze')
        ->assertRedirect('/sherlock/issues/'.$issue->id)
        ->assertSessionHas('sherlock.status');

    Bus::assertDispatched(AnalyzeIssueJob::class, fn (AnalyzeIssueJob $job): bool => $job->issueId === $issue->id && $job->fresh === true);
    Bus::assertNotDispatched(NotifyIssueJob::class);
    expect(app(IssueRepository::class)->find($issue->id)?->aiStatus)->toBe(AiStatus::Pending);
});

it('returns 404 for actions on unknown issues and rejects GET on actions', function (): void {
    Bus::fake();

    $this->post('/sherlock/issues/999999/resolve')->assertNotFound();
    $this->post('/sherlock/issues/999999/reanalyze')->assertNotFound();
    $this->get('/sherlock/issues/1/resolve')->assertStatus(405);

    Bus::assertNothingDispatched();
});

it('protects the actions with CSRF', function (): void {
    $issue = D::issueWith('fp-csrf', 'CSRF issue');

    // The test kernel skips CSRF verification; check the forms carry the token and the
    // routes sit in the "web" group (which verifies it in a real request).
    $this->get('/sherlock/issues/'.$issue->id)->assertOk()->assertSee('name="_token"', false);

    $route = app('router')->getRoutes()->getByName('sherlock.issues.resolve');
    expect($route?->methods())->toBe(['POST'])
        ->and($route?->gatherMiddleware())->toContain('web');
});

it('enforces the CSRF token outside the test kernel bypass', function (): void {
    $issue = D::issueWith('fp-csrf-real', 'CSRF real issue');

    // VerifyCsrfToken skips the check while app('env') === 'testing'; lift that for one request.
    $this->app->instance('env', 'production');

    try {
        $this->post('/sherlock/issues/'.$issue->id.'/resolve')->assertStatus(419);
    } finally {
        $this->app->instance('env', 'testing');
    }

    expect(dashboardIssueStatus($issue->id))->toBe(IssueStatus::Open);
});
