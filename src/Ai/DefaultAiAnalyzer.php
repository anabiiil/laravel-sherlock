<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Ai;

use Anabil\Sherlock\Contracts\AiAnalyzer;
use Anabil\Sherlock\Contracts\AiBudget;
use Anabil\Sherlock\Contracts\AiProviderFactory;
use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Data\AiAnalysisResult;
use Anabil\Sherlock\Data\IssueRecord;
use Anabil\Sherlock\Enums\AiStatus;
use Anabil\Sherlock\Sherlock;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Once-per-issue AI analysis (PLAN §12). A stored analysis is reused for every repeat with
 * no HTTP call; re-analysis only when $fresh (manual request, regression) or when the
 * repository reset ai_status (regression -> pending). A per-issue cache lock stops two
 * workers from paying for the same analysis. Never throws: failures are stored as "failed".
 *
 * A re-analysis ($fresh) that is skipped or fails never downgrades an existing analysis.
 */
final class DefaultAiAnalyzer implements AiAnalyzer
{
    private const LOCK_KEY = 'sherlock:ai:analyze:';

    public function __construct(
        private readonly IssueRepository $issues,
        private readonly AiProviderFactory $providers,
        private readonly AiBudget $budget,
        private readonly PromptBuilder $prompts,
        private readonly Repository $config,
        private readonly Cache $cache,
        private readonly ?Sherlock $sherlock = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function analyze(int|string $issueId, bool $fresh = false): AiAnalysisResult
    {
        try {
            return $this->run($issueId, $fresh);
        } catch (Throwable $e) {
            // Last-resort guard (e.g. the repository itself failing).
            $this->report($issueId, $e);

            return AiAnalysisResult::failed($this->message($e));
        }
    }

    public function shouldAnalyze(IssueRecord $issue, bool $fresh = false): bool
    {
        return $this->skipReason($issue) === null && ($fresh || ! $issue->hasAiAnalysis());
    }

    private function run(int|string $issueId, bool $fresh): AiAnalysisResult
    {
        $issue = $this->issues->find($issueId);

        if ($issue === null) {
            return AiAnalysisResult::failed('Issue not found.');
        }

        if (! $fresh && $issue->hasAiAnalysis()) {
            return $this->stored($issue);
        }

        $skip = $this->skipReason($issue);
        if ($skip !== null) {
            return $this->skip($issue, $skip);
        }

        $lock = $this->lock($issue->id);
        if ($lock !== null && ! $lock->get()) {
            // Another worker is analyzing this issue right now.
            return AiAnalysisResult::skipped(AiStatus::Pending);
        }

        try {
            // Re-read under the lock: the other worker may have just finished.
            $issue = $this->issues->find($issueId) ?? $issue;
            if (! $fresh && $issue->hasAiAnalysis()) {
                return $this->stored($issue);
            }

            return $this->call($issue);
        } finally {
            $lock?->release();
        }
    }

    private function call(IssueRecord $issue): AiAnalysisResult
    {
        if (! $this->budget->consume()) {
            return $this->skip($issue, AiStatus::SkippedBudget);
        }

        $model = null;

        try {
            $response = $this->paused(function () use ($issue, &$model) {
                $request = $this->prompts->build($issue);
                $provider = $this->providers->driver();
                $model = $request->model ?? $provider->name();

                return $provider->complete($request);
            });

            if (trim($response->content) === '') {
                throw AiProviderException::invalidResponse($model ?? 'ai', 'empty completion.');
            }

            $result = AiAnalysisResult::done(trim($response->content), $response->model !== '' ? $response->model : (string) $model, new DateTimeImmutable);
            $this->issues->saveAiAnalysis($issue->id, $result);

            return $result;
        } catch (Throwable $e) {
            $this->report($issue->id, $e);

            if (! $issue->hasAiAnalysis()) {
                $this->safely(fn () => $this->issues->updateAiStatus($issue->id, AiStatus::Failed));
            }

            return AiAnalysisResult::failed($this->message($e), $model);
        }
    }

    /** Disabled / type not allowed -> skipped_disabled; below min_severity -> skipped_severity. */
    private function skipReason(IssueRecord $issue): ?AiStatus
    {
        return (new AiEligibility($this->config))->skipReason($issue->type, $issue->severity);
    }

    private function skip(IssueRecord $issue, AiStatus $status): AiAnalysisResult
    {
        // Keep an existing analysis visible when a re-analysis is skipped.
        if (! $issue->hasAiAnalysis() && $issue->aiStatus !== $status) {
            $this->issues->updateAiStatus($issue->id, $status);
        }

        return AiAnalysisResult::skipped($status);
    }

    private function stored(IssueRecord $issue): AiAnalysisResult
    {
        return AiAnalysisResult::done((string) $issue->aiAnalysis, (string) $issue->aiModel, $issue->aiAnalyzedAt, true);
    }

    private function lock(int|string $issueId): ?Lock
    {
        try {
            $store = $this->cache->getStore();
        } catch (Throwable) {
            return null;
        }

        if (! $store instanceof LockProvider) {
            return null;
        }

        $timeout = $this->config->get('sherlock.ai.timeout', 30);

        // Outlives the HTTP timeout; released explicitly when done.
        return $store->lock(self::LOCK_KEY.$issueId, (is_numeric($timeout) ? (int) $timeout : 30) + 60);
    }

    /**
     * Run without recording the package's own work (schema reads, the provider HTTP call).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function paused(callable $callback): mixed
    {
        return $this->sherlock !== null ? $this->sherlock->pause($callback(...)) : $callback();
    }

    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            // Storage failure while recording a failure: nothing more to do.
        }
    }

    private function report(int|string $issueId, Throwable $e): void
    {
        $this->safely(fn () => $this->logger?->warning('[sherlock] AI analysis failed', [
            'issue_id' => $issueId,
            'exception' => $e::class,
            'error' => $this->message($e),
        ]));
    }

    private function message(Throwable $e): string
    {
        // Other exceptions (e.g. a QueryException) may carry SQL bindings: class name only.
        $message = $e instanceof AiProviderException || $e instanceof InvalidArgumentException
            ? $e->getMessage()
            : 'Unexpected '.class_basename($e).' during AI analysis.';
        $key = $this->config->get('sherlock.ai.api_key');

        return AiProviderException::scrub($message, is_string($key) ? $key : null);
    }
}
