<?php

declare(strict_types=1);

namespace Anabil\Tracing\Jobs;

use Anabil\Tracing\Contracts\DigestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Builds and sends the digest off the scheduler process. Re-checks isDue() inside
 * DigestService::send() (unless forced), so duplicate dispatches send only once.
 * Not retried: a retry after a partial delivery would send the digest twice.
 */
final class SendDigestJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly bool $force = false,
    ) {
        QueueTarget::apply($this);
    }

    public function handle(DigestService $digest): void
    {
        $digest->send($this->force);
    }
}
