<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Collectors;

use Anabil\Sherlock\Context\UnitLifecycle;
use Anabil\Sherlock\Contracts\Collector;
use Anabil\Sherlock\Data\EntryCandidate;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Recording\Policies\IgnorePolicy;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Throwable;

/**
 * One unit per queue job: JobProcessing starts, JobProcessed / JobExceptionOccurred /
 * JobFailed stop (whichever comes first; the others are ignored). Starts and stops are
 * paired through the job object id, so a JobFailed for a job that never started (max
 * attempts exceeded at pop) cannot stop someone else's trace.
 *
 * A job processed while another unit is active (sync queue inside a request / command)
 * is nested: it belongs to the outer trace and records nothing on its own.
 *
 * The package's own jobs (persist / AI / notify / digest / throttled summaries) are never units,
 * whatever ignore.jobs says: tracing a PersistTraceJob would persist a trace that queues
 * another PersistTraceJob, forever. Capture
 * is also paused while they run, and their exceptions are never recorded, so nothing they do
 * (provider / webhook HTTP calls, warnings, a failure reported by the worker) can become an
 * orphan trace and an issue about the package itself.
 */
final class JobCollector implements Collector
{
    /** @var array<int, true> */
    private array $started = [];

    /** @var array<int, true> package jobs in progress (capture held) */
    private array $own = [];

    public function __construct(
        private readonly Dispatcher $events,
        private readonly UnitLifecycle $lifecycle,
        private readonly ?LogCollector $logs = null,
    ) {}

    public function register(): void
    {
        $this->events->listen(JobProcessing::class, $this->processing(...));
        $this->events->listen(JobProcessed::class, $this->processed(...));
        $this->events->listen(JobExceptionOccurred::class, $this->exceptionOccurred(...));
        $this->events->listen(JobFailed::class, $this->jobFailed(...));
    }

    public function processing(JobProcessing $event): void
    {
        try {
            $job = $event->job;
            $name = self::name($job);
            if (self::isPackageJob($name)) {
                $id = spl_object_id($job);
                if (! isset($this->own[$id])) {
                    $this->own[$id] = true;
                    $this->lifecycle->manager()->hold();
                }

                return;
            }
            $this->started[spl_object_id($job)] = true;

            $context = $this->lifecycle->begin(new EntryCandidate(EntryType::Job, $name));

            if ($context->isActive() && $this->lifecycle->manager()->depth() === 1) {
                $context->meta('queue', $job->getQueue());
                $context->meta('connection', $event->connectionName);
                $context->meta('attempts', $job->attempts());
            }
        } catch (Throwable $e) {
            $this->lifecycle->failures()->report($e);
        }
    }

    public function processed(JobProcessed $event): void
    {
        $this->finish($event->job, null);
    }

    public function exceptionOccurred(JobExceptionOccurred $event): void
    {
        $this->finish($event->job, $event->exception);
    }

    public function jobFailed(JobFailed $event): void
    {
        $this->finish($event->job, $event->exception);
    }

    private function finish(Job $job, ?Throwable $exception): void
    {
        try {
            $id = spl_object_id($job);
            if (isset($this->own[$id])) {
                unset($this->own[$id]);
                $this->lifecycle->manager()->resume();
                if ($exception !== null) {
                    // The worker reports it right after this event: never a trace of its own.
                    $this->logs?->ignoreException($exception);
                }

                return;
            }

            if (! isset($this->started[$id])) {
                return;
            }
            unset($this->started[$id]);

            if ($exception !== null) {
                // The worker reports the exception only after this event: attach it now.
                $this->logs?->captureException($exception);

                if ($this->lifecycle->manager()->depth() === 1) {
                    $this->lifecycle->manager()->current()->markFailed();
                }
            }

            $this->lifecycle->end();
        } catch (Throwable $e) {
            $this->lifecycle->failures()->report($e);
        }
    }

    public static function isPackageJob(string $name): bool
    {
        return IgnorePolicy::isPackageJob($name);
    }

    private static function name(Job $job): string
    {
        try {
            return $job->resolveName();
        } catch (Throwable) {
            return $job->getName();
        }
    }
}
