<?php

declare(strict_types=1);

use Anabil\Sherlock\Ai\AiManager;
use Anabil\Sherlock\Contracts\AiAnalyzer;
use Anabil\Sherlock\Contracts\AiBudget;
use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Tests\Feature\Storage\StorageFixtures as F;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function aiCommandIssue(Severity $severity = Severity::Critical): int|string
{
    return app(IssueRepository::class)
        ->recordOccurrence(F::finding('cmd-issue-'.$severity->value, $severity), [], CarbonImmutable::now())
        ->issueId;
}

beforeEach(function (): void {
    config()->set('sherlock.ai.enabled', true);
    config()->set('sherlock.ai.driver', 'openai');
    config()->set('sherlock.ai.api_key', 'sk-test');
    config()->set('sherlock.ai.min_severity', 'critical');
    app(AiManager::class)->forgetDrivers();
    app()->forgetInstance(AiBudget::class);
    app()->forgetInstance(AiAnalyzer::class);
    Cache::flush();

    Http::preventStrayRequests();
    Http::fake(['api.openai.com/*' => Http::response([
        'model' => 'gpt-4o-mini',
        'choices' => [['message' => ['content' => "## Root cause\nAdd an index on users.email."], 'finish_reason' => 'stop']],
    ])]);
});

it('is registered', function (): void {
    expect(array_keys(Artisan::all()))->toContain('sherlock:analyze');
});

it('analyzes synchronously and prints the analysis', function (): void {
    $id = aiCommandIssue();

    $this->artisan('sherlock:analyze', ['issue' => (string) $id])
        ->expectsOutputToContain('gpt-4o-mini')
        ->expectsOutputToContain('Add an index on users.email.')
        ->assertSuccessful();

    Http::assertSentCount(1);
});

it('prints the stored analysis without calling the AI again, unless --fresh', function (): void {
    $id = aiCommandIssue();
    $this->artisan('sherlock:analyze', ['issue' => (string) $id])->assertSuccessful();

    $this->artisan('sherlock:analyze', ['issue' => (string) $id])
        ->expectsOutputToContain('stored, no AI call')
        ->expectsOutputToContain('Add an index on users.email.')
        ->assertSuccessful();
    Http::assertSentCount(1);

    $this->artisan('sherlock:analyze', ['issue' => (string) $id, '--fresh' => true])
        ->doesntExpectOutputToContain('stored, no AI call')
        ->assertSuccessful();
    Http::assertSentCount(2);
});

it('explains why an issue was skipped', function (): void {
    $id = aiCommandIssue(Severity::Warning);

    $this->artisan('sherlock:analyze', ['issue' => (string) $id])
        ->expectsOutputToContain('below sherlock.ai.min_severity')
        ->assertSuccessful();

    config()->set('sherlock.ai.enabled', false);
    $this->artisan('sherlock:analyze', ['issue' => (string) $id])
        ->expectsOutputToContain('disabled')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('reports budget exhaustion', function (): void {
    config()->set('sherlock.ai.daily_limit', 0);
    app()->forgetInstance(AiBudget::class);
    app()->forgetInstance(AiAnalyzer::class);

    $this->artisan('sherlock:analyze', ['issue' => (string) aiCommandIssue()])
        ->expectsOutputToContain('daily AI budget')
        ->assertSuccessful();
});

it('fails for an unknown issue or a provider error', function (): void {
    $this->artisan('sherlock:analyze', ['issue' => '987654'])
        ->expectsOutputToContain('Issue not found')
        ->assertFailed();

    config()->set('sherlock.ai.driver', 'mistral');
    Http::fake(['api.mistral.ai/*' => Http::response(['message' => 'Unauthorized'], 401)]);

    $this->artisan('sherlock:analyze', ['issue' => (string) aiCommandIssue()])
        ->expectsOutputToContain('HTTP 401')
        ->assertFailed();
});
