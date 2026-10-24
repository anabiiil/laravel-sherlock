<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Support;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Jobs\DatabaseJob;
use Throwable;

/**
 * Time a queue job waited between becoming available and starting, from data the worker
 * already holds (no I/O):
 *
 *  - database driver: the job row's available_at (seconds; already includes the delay and is
 *    moved forward on release, so retries are measured correctly)
 *  - otherwise, first attempt only: payload "pushedAt" (Horizon, microseconds) else "createdAt"
 *    (Laravel 12+, seconds), plus the payload "delay" (Laravel 12+). On later attempts the push
 *    time includes earlier runs and backoff, so nothing is reported.
 *
 * Laravel 11 without Horizon puts neither in the payload: null (only the database driver works).
 * Second-precision sources are accurate to ±1s, fine for "waited 45s" diagnostics.
 */
final class QueueWait
{
    /**
     * @param  float  $startedAt  microtime(true) when the job started
     */
    public static function measure(Job $job, float $startedAt): ?float
    {
        try {
            $availableAt = self::availableAt($job);
        } catch (Throwable) {
            return null;
        }

        if ($availableAt === null || $availableAt <= 0) {
            return null;
        }

        return round(max(0.0, $startedAt - $availableAt) * 1000, 1);
    }

    /** Unix time (seconds, fractional) when the job became available to workers. */
    public static function availableAt(Job $job): ?float
    {
        if ($job instanceof DatabaseJob) {
            $value = $job->getJobRecord()->available_at ?? null;

            return is_numeric($value) ? (float) $value : null;
        }

        if ($job->attempts() > 1) {
            return null;
        }

        $payload = $job->payload();
        $pushed = $payload['pushedAt'] ?? $payload['createdAt'] ?? null;
        if (! is_numeric($pushed)) {
            return null;
        }

        $delay = $payload['delay'] ?? null;

        return (float) $pushed + (is_numeric($delay) ? max(0.0, (float) $delay) : 0.0);
    }
}
