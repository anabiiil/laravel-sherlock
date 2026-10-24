<?php

declare(strict_types=1);

use Anabil\Tracing\Ai\AiManager;
use Anabil\Tracing\Ai\DefaultAiAnalyzer;
use Anabil\Tracing\Contracts\AiAnalyzer;
use Anabil\Tracing\Contracts\AiBudget;
use Anabil\Tracing\Contracts\IssueRepository;
use Anabil\Tracing\Contracts\IssueService;
use Anabil\Tracing\Contracts\NotificationDispatcher;
use Anabil\Tracing\Data\Finding;
use Anabil\Tracing\Data\NotificationMessage;
use Anabil\Tracing\Data\SourceLocation;
use Anabil\Tracing\Enums\AiStatus;
use Anabil\Tracing\Enums\IssueStatus;
use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Enums\Severity;
use Anabil\Tracing\Issues\IssueContextBuilder;
use Anabil\Tracing\Jobs\AnalyzeIssueJob;
use Anabil\Tracing\Jobs\NotifyIssueJob;
use Anabil\Tracing\Tests\Feature\Storage\StorageFixtures as F;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const AI_SQL = 'select * from `tracing_issues` where `fingerprint` = ? and `status` = ?';

function aiFinding(
    string $fingerprint = 'ai-issue-1',
    IssueType $type = IssueType::SlowQuery,
    Severity $severity = Severity::Critical,
    string $description = 'Query took 912ms',
): Finding {
    return new Finding(
        $type,
        $severity,
        $type->label().' detected',
        $description,
        $fingerprint,
        ['time_ms' => 912.4, 'count' => 1],
        new SourceLocation('app/Http/Controllers/UserController.php', 42),
        $type->isQueryRelated() ? AI_SQL : null,
        $type->isQueryRelated() ? 'fp-ai' : null,
    );
}

/** Creates the issue through the real repository with a context built like production. */
function aiIssue(?Finding $finding = null, array $extraContext = []): int|string
{
    $finding ??= aiFinding();
    $query = F::query(AI_SQL, 912.4, ['binding-secret-ABC', 'paid'], 'fp-ai');
    $recorded = F::recorded(F::traceData([$query]), [$finding], explainPlans: ['fp-ai' => F::plan()]);
    $context = (new IssueContextBuilder)->build($finding, $recorded, 'trace-1') + $extraContext;

    return app(IssueRepository::class)->recordOccurrence($finding, $context, CarbonImmutable::now(), 'trace-1')->issueId;
}

function aiAnthropicOk(string $text = "## Root cause\nMissing index.\n## Fix\nAdd it."): array
{
    return [
        'model' => 'claude-sonnet-5-5',
        'content' => [['type' => 'text', 'text' => $text]],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
        'stop_reason' => 'end_turn',
    ];
}

function aiAnalyzer(): DefaultAiAnalyzer
{
    return app(AiAnalyzer::class);
}

function aiIssueRecord(int|string $id): Anabil\Tracing\Data\IssueRecord
{
    return app(IssueRepository::class)->find($id);
}

beforeEach(function (): void {
    config()->set('tracing.ai.enabled', true);
    config()->set('tracing.ai.driver', 'anthropic');
    config()->set('tracing.ai.api_key', 'sk-ant-test-key');
    config()->set('tracing.ai.min_severity', 'warning');
    config()->set('tracing.ai.daily_limit', 10);
    config()->set('tracing.ai.analyze', ['performance' => true, 'exceptions' => false]);
    config()->set('tracing.queue.connection', 'sync');
    config()->set('queue.default', 'sync');
    app(AiManager::class)->forgetDrivers();
    app()->forgetInstance(AiBudget::class);
    app()->forgetInstance(AiAnalyzer::class);
    Cache::flush();

    Http::preventStrayRequests();
    // Tests swap $this->aiResponder (a response or a closure) instead of re-faking: stubs stack.
    $this->aiResponder = fn () => Http::response(aiAnthropicOk());
    Http::fake(['api.anthropic.com/*' => fn (Request $request) => ($this->aiResponder)($request)]);
});

it('is bound to the default analyzer', function (): void {
    expect(app(AiAnalyzer::class))->toBeInstanceOf(DefaultAiAnalyzer::class);
});

it('analyzes an issue once and stores the result', function (): void {
    $id = aiIssue();

    $result = aiAnalyzer()->analyze($id);

    expect($result->isDone())->toBeTrue()
        ->and($result->fromCache)->toBeFalse()
        ->and($result->model)->toBe('claude-sonnet-5-5')
        ->and($result->analysis)->toContain('Missing index');

    $issue = aiIssueRecord($id);
    expect($issue->aiStatus)->toBe(AiStatus::Done)
        ->and($issue->aiAnalysis)->toContain('Missing index')
        ->and($issue->aiModel)->toBe('claude-sonnet-5-5')
        ->and($issue->aiAnalyzedAt)->not->toBeNull()
        ->and(app(AiBudget::class)->used())->toBe(1);

    Http::assertSentCount(1);
});

it('reuses the stored analysis for repeats without any HTTP call', function (): void {
    $id = aiIssue();
    aiAnalyzer()->analyze($id);

    // The issue recurs (more occurrences), then is analyzed again by a later job.
    aiIssue();
    $second = aiAnalyzer()->analyze($id);

    expect($second->isDone())->toBeTrue()
        ->and($second->fromCache)->toBeTrue()
        ->and($second->analysis)->toContain('Missing index')
        ->and(aiIssueRecord($id)->occurrences)->toBe(2)
        ->and(app(AiBudget::class)->used())->toBe(1);

    Http::assertSentCount(1);
});

it('re-analyzes when fresh is requested', function (): void {
    $id = aiIssue();
    aiAnalyzer()->analyze($id);

    $this->aiResponder = fn () => Http::response(aiAnthropicOk('## Root cause\nSecond opinion.'));
    $result = aiAnalyzer()->analyze($id, fresh: true);

    expect($result->fromCache)->toBeFalse()
        ->and(aiIssueRecord($id)->aiAnalysis)->toContain('Second opinion');

    Http::assertSentCount(2);
});

it('re-analyzes a regressed issue through the AnalyzeIssueJob -> NotifyIssueJob chain', function (): void {
    $notified = aiFakeDispatcher();
    app()->forgetInstance(IssueService::class);
    $service = app(IssueService::class);
    $repo = app(IssueRepository::class);

    $first = $service->recordFindings('trace-1', F::recorded(findings: [aiFinding('ai-regress')]))[0];
    expect(aiIssueRecord($first->issueId)->aiStatus)->toBe(AiStatus::Done);

    // Recurring: reuses the analysis (no job, no call).
    $service->recordFindings('trace-2', F::recorded(findings: [aiFinding('ai-regress')]));
    Http::assertSentCount(1);

    $repo->updateStatus($first->issueId, IssueStatus::Resolved);
    $service->recordFindings('trace-3', F::recorded(findings: [aiFinding('ai-regress')]));

    expect(aiIssueRecord($first->issueId)->aiStatus)->toBe(AiStatus::Done)
        ->and($notified->getArrayCopy())->toBe([$first->issueId, $first->issueId]);
    Http::assertSentCount(2);
});

it('analyzes a regressed issue (ai_status pending) even without fresh', function (): void {
    $id = aiIssue();
    aiAnalyzer()->analyze($id);
    app(IssueRepository::class)->updateStatus($id, IssueStatus::Resolved);

    aiIssue(); // comes back -> regressed, ai_status reset to pending

    expect(aiIssueRecord($id)->aiStatus)->toBe(AiStatus::Pending);
    expect(aiAnalyzer()->analyze($id)->fromCache)->toBeFalse();
    Http::assertSentCount(2);
});

it('skips with skipped_budget and no HTTP call when the budget is exhausted', function (): void {
    config()->set('tracing.ai.daily_limit', 1);
    app()->forgetInstance(AiBudget::class);
    app()->forgetInstance(AiAnalyzer::class);

    $a = aiIssue(aiFinding('budget-a'));
    $b = aiIssue(aiFinding('budget-b'));

    expect(aiAnalyzer()->analyze($a)->isDone())->toBeTrue();

    $result = aiAnalyzer()->analyze($b);

    expect($result->status)->toBe(AiStatus::SkippedBudget)
        ->and(aiIssueRecord($b)->aiStatus)->toBe(AiStatus::SkippedBudget);
    Http::assertSentCount(1);
});

it('makes no call at all with daily_limit = 0', function (): void {
    config()->set('tracing.ai.daily_limit', 0);
    app()->forgetInstance(AiBudget::class);
    app()->forgetInstance(AiAnalyzer::class);

    expect(aiAnalyzer()->analyze(aiIssue())->status)->toBe(AiStatus::SkippedBudget);
    Http::assertNothingSent();
});

it('records failed (never throws) on provider errors', function (Closure $response): void {
    $this->aiResponder = $response;
    $id = aiIssue();

    $result = aiAnalyzer()->analyze($id);

    expect($result->status)->toBe(AiStatus::Failed)
        ->and($result->error)->not->toBeEmpty()
        ->and($result->error)->not->toContain('sk-ant-test-key')
        ->and(aiIssueRecord($id)->aiStatus)->toBe(AiStatus::Failed)
        ->and(aiIssueRecord($id)->aiAnalysis)->toBeNull();
})->with([
    'server error' => [fn () => fn () => Http::response(['error' => ['message' => 'Overloaded']], 529)],
    'unauthorized' => [fn () => fn () => Http::response(['error' => ['message' => 'invalid x-api-key sk-ant-test-key']], 401)],
    'timeout' => [fn () => fn () => throw new ConnectionException('cURL error 28: timed out')],
    'empty completion' => [fn () => fn () => Http::response(aiAnthropicOk(''))],
]);

it('keeps an existing analysis when a fresh re-analysis fails', function (): void {
    $id = aiIssue();
    aiAnalyzer()->analyze($id);

    $this->aiResponder = fn () => Http::response([], 500);
    $result = aiAnalyzer()->analyze($id, fresh: true);

    expect($result->status)->toBe(AiStatus::Failed)
        ->and(aiIssueRecord($id)->aiStatus)->toBe(AiStatus::Done)
        ->and(aiIssueRecord($id)->aiAnalysis)->toContain('Missing index');
});

it('still sends the notification when the analysis fails (sync chain)', function (): void {
    $this->aiResponder = fn () => throw new ConnectionException('timed out');
    $notified = aiFakeDispatcher();
    $id = aiIssue();

    Bus::chain([new AnalyzeIssueJob($id), new NotifyIssueJob($id)])->dispatch();

    expect($notified->getArrayCopy())->toBe([$id])
        ->and(aiIssueRecord($id)->aiStatus)->toBe(AiStatus::Failed);
});

it('still sends the notification when the analyzer itself blows up', function (): void {
    $notified = aiFakeDispatcher();
    app()->instance(AiAnalyzer::class, new class implements AiAnalyzer
    {
        public function analyze(int|string $issueId, bool $fresh = false): Anabil\Tracing\Data\AiAnalysisResult
        {
            throw new RuntimeException('boom');
        }

        public function shouldAnalyze(Anabil\Tracing\Data\IssueRecord $issue, bool $fresh = false): bool
        {
            return true;
        }
    });

    Bus::chain([new AnalyzeIssueJob(5), new NotifyIssueJob(5)])->dispatch();

    expect($notified->getArrayCopy())->toBe([5]);
});

it('honours the exceptions toggle (default off)', function (): void {
    $id = aiIssue(aiFinding('ai-exception', IssueType::Exception), ['exception' => [
        'level' => 'error', 'class' => 'RuntimeException', 'message' => 'Card declined', 'file' => 'app/Billing.php', 'line' => 3, 'frames' => [],
    ]]);

    expect(aiAnalyzer()->analyze($id)->status)->toBe(AiStatus::SkippedDisabled)
        ->and(aiIssueRecord($id)->aiStatus)->toBe(AiStatus::SkippedDisabled);
    Http::assertNothingSent();

    config()->set('tracing.ai.analyze.exceptions', true);

    expect(aiAnalyzer()->analyze($id)->isDone())->toBeTrue();
    Http::assertSent(fn (Request $r): bool => str_contains($r->data()['messages'][0]['content'], 'Class: RuntimeException'));
});

it('skips performance issues when the performance toggle is off', function (): void {
    config()->set('tracing.ai.analyze.performance', false);

    expect(aiAnalyzer()->analyze(aiIssue())->status)->toBe(AiStatus::SkippedDisabled);
    Http::assertNothingSent();
});

it('skips when AI is disabled', function (): void {
    config()->set('tracing.ai.enabled', false);
    $id = aiIssue();

    expect(aiAnalyzer()->analyze($id)->status)->toBe(AiStatus::SkippedDisabled)
        ->and(aiIssueRecord($id)->aiStatus)->toBe(AiStatus::SkippedDisabled);
    Http::assertNothingSent();
});

it('skips issues below min_severity', function (): void {
    config()->set('tracing.ai.min_severity', 'critical');
    $id = aiIssue(aiFinding('ai-warning', severity: Severity::Warning));

    expect(aiAnalyzer()->analyze($id)->status)->toBe(AiStatus::SkippedSeverity)
        ->and(aiIssueRecord($id)->aiStatus)->toBe(AiStatus::SkippedSeverity);
    Http::assertNothingSent();
});

it('decides shouldAnalyze from enabled, type, severity and stored analysis', function (): void {
    $id = aiIssue();
    $issue = aiIssueRecord($id);

    expect(aiAnalyzer()->shouldAnalyze($issue))->toBeTrue();

    aiAnalyzer()->analyze($id);
    $analyzed = aiIssueRecord($id);

    expect(aiAnalyzer()->shouldAnalyze($analyzed))->toBeFalse()
        ->and(aiAnalyzer()->shouldAnalyze($analyzed, fresh: true))->toBeTrue();

    config()->set('tracing.ai.min_severity', 'critical');
    expect(aiAnalyzer()->shouldAnalyze(aiIssueRecord(aiIssue(aiFinding('w', severity: Severity::Warning)))))->toBeFalse();

    config()->set('tracing.ai.enabled', false);
    expect(aiAnalyzer()->shouldAnalyze($issue))->toBeFalse();
});

it('does not pay twice while another worker holds the issue lock', function (): void {
    $id = aiIssue();
    $lock = Cache::lock('tracing:ai:analyze:'.$id, 60);
    expect($lock->get())->toBeTrue();

    $result = aiAnalyzer()->analyze($id);

    expect($result->status)->toBe(AiStatus::Pending)
        ->and(app(AiBudget::class)->used())->toBe(0);
    Http::assertNothingSent();

    $lock->release();
    expect(aiAnalyzer()->analyze($id)->isDone())->toBeTrue();
});

it('fails gracefully for an unknown issue', function (): void {
    $result = aiAnalyzer()->analyze(999999);

    expect($result->status)->toBe(AiStatus::Failed)->and($result->error)->toBe('Issue not found.');
    Http::assertNothingSent();
});

it('sends no real data: planted secrets are redacted, SQL is normalized, no bindings', function (): void {
    config()->set('tracing.ai.send_code_snippet', false);
    $id = aiIssue(aiFinding(description: 'Slow for user john.doe@example.com password=hunter2 Bearer abcdef1234567890xyz'), [
        'recommendation' => 'Ask ops@example.com, api_key=sk_live_123456789abcdef',
    ]);

    aiAnalyzer()->analyze($id);

    Http::assertSent(function (Request $r): bool {
        $payload = json_encode($r->data(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach (['john.doe@example.com', 'hunter2', 'abcdef1234567890xyz', 'ops@example.com', 'sk_live_123456789abcdef', 'binding-secret-ABC', "'paid'", '/users?page=2'] as $secret) {
            if (str_contains($payload, $secret)) {
                return false;
            }
        }

        return str_contains($r->data()['messages'][0]['content'], '`fingerprint` = ? and `status` = ?');
    });
});

it('includes the table schema and existing indexes of the referenced tables', function (): void {
    aiAnalyzer()->analyze(aiIssue());

    Http::assertSent(fn (Request $r): bool => str_contains($prompt = $r->data()['messages'][0]['content'], 'Table schema')
        && str_contains($prompt, '`tracing_issues`')
        && str_contains($prompt, 'fingerprint varchar')
        && str_contains($prompt, 'unique(fingerprint)'));
});

it('omits the schema when include_schema is off', function (): void {
    config()->set('tracing.ai.include_schema', false);

    aiAnalyzer()->analyze(aiIssue());

    Http::assertSent(fn (Request $r): bool => ! str_contains($r->data()['messages'][0]['content'], 'Table schema'));
});

function aiFakeDispatcher(): ArrayObject
{
    $notified = new ArrayObject;

    app()->instance(NotificationDispatcher::class, new class($notified) implements NotificationDispatcher
    {
        public function __construct(private ArrayObject $notified) {}

        public function notifyIssue(int|string $issueId): void
        {
            $this->notified[] = $issueId;
        }

        public function send(NotificationMessage $message, ?array $channels = null): void {}

        public function channels(): array
        {
            return [];
        }
    });

    return $notified;
}
