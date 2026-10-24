<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Collectors;

use Anabil\Sherlock\Context\UnitLifecycle;
use Anabil\Sherlock\Context\WorkerMemoryMonitor;
use Anabil\Sherlock\Contracts\Collector;
use Anabil\Sherlock\Data\EntryCandidate;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Recording\Policies\IgnorePolicy;
use Anabil\Sherlock\Support\QueueWait;
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
 *
 * Per job, on the captured top-level trace: the queue wait (QueueWait, from data the worker
 * already holds) and, in workers (never the sync queue), one memory sample for the
 * WorkerMemoryMonitor after the job; a detected leak is attached to that job's trace.
 */
final class JobCollector implements Collector
{
    /** @var array<int, string> job object id => job name */
    private array $started = [];

    /** @var array<int, true> package jobs in progress (capture held) */
    private array $own = [];

    public function __construct(
        private readonly Dispatcher $events,
        private readonly UnitLifecycle $lifecycle,
        private readonly ?LogCollector $logs = null,
        private readonly ?WorkerMemoryMonitor $memory = null,
        private readonly bool $queueWait = true,
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
            $this->started[spl_object_id($job)] = $name;

            $startedAt = microtime(true);
            $context = $this->lifecycle->begin(new EntryCandidate(EntryType::Job, $name), $startedAt);

            if ($context->isActive() && $this->lifecycle->manager()->depth() === 1) {
                $context->meta('queue', $job->getQueue());
                $context->meta('connection', $event->connectionName);
                $context->meta('attempts', $job->attempts());

                if ($this->queueWait) {
                    $this->lifecycle->manager()->active()?->setQueueWaitMs(QueueWait::measure($job, $startedAt));
                }
            }
        } catch (Throwable $e) {
            $this->lifecycle->failures()->report($e);
        }
    }

    public function processed(JobProcessed $event): void
    {
        $this->finish($event->job, null, $event->connectionName);
    }

    public function exceptionOccurred(JobExceptionOccurred $event): void
    {
        $this->finish($event->job, $event->exception, $event->connectionName);
    }

    public function jobFailed(JobFailed $event): void
    {
        $this->finish($event->job, $event->exception, $event->connectionName);
    }

    private function finish(Job $job, ?Throwable $exception, ?string $connection = null): void
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
            $name = $this->started[$id];
            unset($this->started[$id]);

            if ($exception !== null) {
                // The worker reports the exception only after this event: attach it now.
                $this->logs?->captureException($exception);

                if ($this->lifecycle->manager()->depth() === 1) {
                    $this->lifecycle->manager()->current()->markFailed();
                }
            }

            $this->sampleMemory($job, $name, $connection);

            $this->lifecycle->end();
        } catch (Throwable $e) {
            $this->lifecycle->failures()->report($e);
        }
    }

    /**
     * One memory_get_usage() per job processed by a worker (never the sync queue, never a nested
     * job); a leak signal goes on the trace of the job that crossed the threshold.
     */
    private function sampleMemory(Job $job, string $name, ?string $connection): void
    {
        $manager = $this->lifecycle->manager();
        if ($this->memory === null || $connection === 'sync' || $job->getConnectionName() === 'sync' || $manager->depth() > 1) {
            return;
        }

        $signal = $this->memory->sample($name);
        if ($signal !== null && $manager->depth() === 1) {
            $manager->active()?->setMemoryLeak($signal);
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
