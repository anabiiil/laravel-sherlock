<?php

declare(strict_types=1);

namespace Anabil\Tracing\Console;

use Anabil\Tracing\Contracts\AiAnalyzer;
use Anabil\Tracing\Enums\AiStatus;
use Illuminate\Console\Command;

/**
 * Run the AI analysis of one issue synchronously. Without --fresh a stored analysis is
 * printed as is (no AI call); --fresh re-analyzes (one budget unit).
 */
final class AnalyzeCommand extends Command
{
    /** @var string */
    protected $signature = 'tracing:analyze {issue : The issue id} {--fresh : Re-analyze even if an analysis is stored}';

    /** @var string */
    protected $description = 'Run (or show) the AI analysis of an issue';

    public function handle(AiAnalyzer $analyzer): int
    {
        $issue = $this->argument('issue');
        if (! is_string($issue) || $issue === '') {
            $this->components->error('Give an issue id.');

            return self::FAILURE;
        }

        $id = ctype_digit($issue) ? (int) $issue : $issue;

        $result = $analyzer->analyze($id, (bool) $this->option('fresh'));

        if ($result->isDone()) {
            $this->components->info(sprintf(
                'Analysis of issue #%s (%s%s%s)',
                $id,
                $result->model ?? 'unknown model',
                $result->analyzedAt !== null ? ', '.$result->analyzedAt->format('Y-m-d H:i') : '',
                $result->fromCache ? ', stored, no AI call' : '',
            ));
            $this->line((string) $result->analysis);

            return self::SUCCESS;
        }

        if ($result->status === AiStatus::Failed) {
            $this->components->error('AI analysis failed: '.($result->error ?? 'unknown error'));

            return self::FAILURE;
        }

        $this->components->warn(match ($result->status) {
            AiStatus::SkippedDisabled => 'Skipped: AI analysis is disabled for this kind of issue (tracing.ai.enabled / tracing.ai.analyze).',
            AiStatus::SkippedSeverity => 'Skipped: the issue severity is below tracing.ai.min_severity.',
            AiStatus::SkippedBudget => 'Skipped: the daily AI budget (tracing.ai.daily_limit) is exhausted.',
            AiStatus::Pending => 'Another worker is analyzing this issue right now; try again shortly.',
            default => 'Skipped: '.$result->status->label().'.',
        });

        return self::SUCCESS;
    }
}
