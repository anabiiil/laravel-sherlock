<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Jobs;

use Anabil\Sherlock\Contracts\NotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends (throttled) notifications for an issue.
 */
final class NotifyIssueJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int|string $issueId,
    ) {
        QueueTarget::apply($this);
    }

    public function handle(NotificationDispatcher $dispatcher): void
    {
        $dispatcher->notifyIssue($this->issueId);
    }
}
