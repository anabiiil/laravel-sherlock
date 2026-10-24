<?php

declare(strict_types=1);

namespace Anabil\Tracing\Jobs;

/**
 * Applies config("tracing.queue") to package jobs.
 */
final class QueueTarget
{
    /**
     * @param  object  $job  a job using the Queueable trait
     */
    public static function apply(object $job): void
    {
        if (! function_exists('config') || ! method_exists($job, 'onQueue') || ! method_exists($job, 'onConnection')) {
            return;
        }

        $connection = config('tracing.queue.connection');
        $queue = config('tracing.queue.name');

        if (is_string($connection) && $connection !== '') {
            $job->onConnection($connection);
        }

        if (is_string($queue) && $queue !== '') {
            $job->onQueue($queue);
        }
    }
}
