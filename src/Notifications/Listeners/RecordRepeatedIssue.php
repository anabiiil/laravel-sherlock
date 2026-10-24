<?php

declare(strict_types=1);

namespace Anabil\Tracing\Notifications\Listeners;

use Anabil\Tracing\Events\IssueRecurred;
use Anabil\Tracing\Notifications\Throttler;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * IssueRecurred -> Throttler. Cheap (config checks first, then one increment + one read) and
 * never throws: a notification problem must never break trace persistence.
 */
final class RecordRepeatedIssue
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function handle(IssueRecurred $event): void
    {
        if ($event->outcome->isIgnored()) {
            return;
        }

        try {
            $this->container->make(Throttler::class)->recordRepeat($event->outcome);
        } catch (Throwable $e) {
            if ($this->container->bound(LoggerInterface::class)) {
                $this->container->make(LoggerInterface::class)
                    ->warning('[tracing] Could not throttle repeat notification: '.$e::class, ['issue_id' => $event->outcome->issueId]);
            }
        }
    }
}
