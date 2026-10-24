<?php

declare(strict_types=1);

namespace Anabil\Tracing\Jobs;

use Anabil\Tracing\Contracts\AiAnalyzer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * First link of Bus::chain([AnalyzeIssueJob, NotifyIssueJob]). Never throws, so the
 * notification job always runs, with or without an analysis.
 */
final class AnalyzeIssueJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int|string $issueId,
        public readonly bool $fresh = false,
    ) {
        QueueTarget::apply($this);
    }

    public function handle(AiAnalyzer $analyzer): void
    {
        try {
            $analyzer->analyze($this->issueId, $this->fresh);
        } catch (Throwable) {
            // AiAnalyzer records failures itself; never break the chain.
        }
    }
}
