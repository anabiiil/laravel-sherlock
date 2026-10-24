<?php

declare(strict_types=1);

namespace Anabil\Tracing\Collectors;

use Anabil\Tracing\Context\UnitLifecycle;
use Anabil\Tracing\Contracts\Collector;
use Anabil\Tracing\Data\EntryCandidate;
use Anabil\Tracing\Enums\EntryType;
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
 */
final class JobCollector implements Collector
{
    /** @var array<int, true> */
    private array $started = [];

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

    private static function name(Job $job): string
    {
        try {
            return $job->resolveName();
        } catch (Throwable) {
            return $job->getName();
        }
    }
}
