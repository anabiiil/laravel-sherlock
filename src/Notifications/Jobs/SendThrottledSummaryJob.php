<?php

declare(strict_types=1);

namespace Anabil\Tracing\Notifications\Jobs;

use Anabil\Tracing\Jobs\QueueTarget;
use Anabil\Tracing\Notifications\Throttler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the throttled "occurred N more times in the last X minutes" summary for an issue,
 * then marks it notified (resetting pending_notify_count). Channel failures are isolated by
 * the dispatcher; anything else is logged once and swallowed so the job never retry-loops.
 */
final class SendThrottledSummaryJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(
        public readonly int|string $issueId,
    ) {
        QueueTarget::apply($this);
    }

    public function handle(Throttler $throttler): void
    {
        try {
            $throttler->flush($this->issueId, $this->canWait());
        } catch (Throwable $e) {
            Log::warning('[tracing] Throttled notification summary failed: '.$e::class, ['issue_id' => $this->issueId]);
        }
    }

    /** A worker-run job may re-dispatch itself with a delay; a sync / direct run may not. */
    private function canWait(): bool
    {
        return $this->job !== null && $this->job->getConnectionName() !== 'sync';
    }
}
