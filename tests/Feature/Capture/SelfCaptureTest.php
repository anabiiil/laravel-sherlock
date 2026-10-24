<?php

declare(strict_types=1);

use Anabil\Sherlock\Context\ContextManager;
use Anabil\Sherlock\Contracts\AiAnalyzer;
use Anabil\Sherlock\Contracts\NotificationDispatcher;
use Anabil\Sherlock\Contracts\RecordingPolicy;
use Anabil\Sherlock\Data\AiAnalysisResult;
use Anabil\Sherlock\Data\EntryCandidate;
use Anabil\Sherlock\Data\IssueRecord;
use Anabil\Sherlock\Data\NotificationMessage;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Facades\Sherlock;
use Anabil\Sherlock\Jobs\AnalyzeIssueJob;
use Anabil\Sherlock\Jobs\NotifyIssueJob;
use Anabil\Sherlock\Notifications\Jobs\SendThrottledSummaryJob;
use Anabil\Sherlock\Recording\Policies\CompositeRecordingPolicy;
use Anabil\Sherlock\Recording\Policies\IgnorePolicy;
use Anabil\Sherlock\Recording\Policies\WatchWindowPolicy;
use Anabil\Sherlock\Tests\Feature\Capture\Support\Capture;
use Anabil\Sherlock\Tests\Feature\Capture\Support\CaptureJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
| The package must never report on itself: its own queue jobs (AI provider / webhook HTTP
| calls, warnings, failures reported by the worker) and sherlock:* commands never become
| traces, orphan traces or issues, whatever the ignore lists say.
*/

beforeEach(function (): void {
    config()->set('sherlock.mode', 'production');
    config()->set('sherlock.sample_rate', 0.0);
    config()->set('sherlock.capture.log_level', 'warning');
    // Even with the default ignore lists emptied by the user.
    config()->set('sherlock.ignore.jobs', []);
    config()->set('sherlock.ignore.commands', []);
    app()->forgetInstance(IgnorePolicy::class);
    app()->forgetInstance(RecordingPolicy::class);

    $this->recorder = Capture::fakeRecorder();
    Http::fake(['*' => Http::response(['ok' => true])]);
});

it('records nothing from the package\'s own jobs: no unit, no orphan logs, no HTTP calls', function (): void {
    app()->instance(AiAnalyzer::class, new class implements AiAnalyzer
    {
        public function analyze(int|string $issueId, bool $fresh = false): AiAnalysisResult
        {
            Http::post('https://api.anthropic.test/v1/messages', ['prompt' => 'x']);
            DB::select('select 1');
            Log::warning('[sherlock] AI analysis failed');
            Log::error('provider exploded');

            return AiAnalysisResult::failed('boom');
        }

        public function shouldAnalyze(IssueRecord $issue, bool $fresh = false): bool
        {
            return true;
        }
    });

    dispatch(new AnalyzeIssueJob(1));
    app()->terminate();

    expect($this->recorder->traces)->toBe([])
        ->and(app(ContextManager::class)->isPaused())->toBeFalse();

    // Capture resumes normally afterwards.
    dispatch(new CaptureJob(1));
    expect($this->recorder->traces)->toHaveCount(1)
        ->and($this->recorder->last()->logs)->toBe([])
        ->and($this->recorder->last()->httpCalls)->toBe([]);
});

it('never records the exception of a failing package job, even when the worker reports it', function (): void {
    app()->instance(NotificationDispatcher::class, new class implements NotificationDispatcher
    {
        public function notifyIssue(int|string $issueId): void
        {
            throw new RuntimeException('slack webhook https://hooks.slack.com/services/T0/B0/secret failed');
        }

        public function send(NotificationMessage $message, ?array $channels = null): void {}

        public function channels(): array
        {
            return [];
        }
    });

    try {
        dispatch(new NotifyIssueJob(1));
    } catch (RuntimeException $e) {
        report($e);
    }
    app()->terminate();

    expect($this->recorder->traces)->toBe([])
        ->and(app(ContextManager::class)->isPaused())->toBeFalse();
});

it('always ignores package jobs (summaries included) and Sherlock commands', function (): void {
    $policy = IgnorePolicy::fromConfig([]);

    expect($policy->ignores(new EntryCandidate(EntryType::Job, SendThrottledSummaryJob::class)))->toBeTrue()
        ->and($policy->ignores(new EntryCandidate(EntryType::Job, AnalyzeIssueJob::class)))->toBeTrue()
        ->and($policy->ignores(new EntryCandidate(EntryType::Command, 'sherlock:prune')))->toBeTrue()
        ->and($policy->ignores(new EntryCandidate(EntryType::Job, CaptureJob::class)))->toBeFalse()
        ->and($policy->ignores(new EntryCandidate(EntryType::Command, 'app:import')))->toBeFalse();
});

it('redacts the raw path used as the name of a request no route matched', function (): void {
    $this->get('/reset/jane.doe@example.com/'.str_repeat('a1B2c3D4', 6))->assertNotFound();

    $trace = $this->recorder->last();

    expect($trace->name)->toStartWith('GET /reset/')
        ->not->toContain('jane.doe@example.com')
        ->not->toContain(str_repeat('a1B2c3D4', 6))
        ->and($trace->uri)->toBe(substr($trace->name, 4));
});

it('keeps the route pattern as the name of a matched request', function (): void {
    Route::get('/users/{email}', fn () => 'ok');

    $this->get('/users/jane.doe@example.com')->assertOk();

    expect($this->recorder->last()->name)->toBe('GET /users/{email}');
});

it('reads no cache at all when the watch window is disabled', function (): void {
    config()->set('sherlock.watch.enabled', false);
    app()->forgetInstance(RecordingPolicy::class);
    Sherlock::watch(10);
    Route::get('/other', fn () => 'ok');

    $policy = app(RecordingPolicy::class);
    $this->get('/other')->assertOk();

    expect($policy)->toBeInstanceOf(CompositeRecordingPolicy::class)
        ->and(array_filter($policy->policies(), static fn ($p): bool => $p instanceof WatchWindowPolicy))->toBe([])
        ->and($this->recorder->last()->full)->toBeFalse();
});
