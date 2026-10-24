<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Collectors;

use Anabil\Sherlock\Context\UnitLifecycle;
use Anabil\Sherlock\Contracts\Collector;
use Anabil\Sherlock\Data\EntryCandidate;
use Anabil\Sherlock\Enums\EntryType;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * One unit per artisan command (CommandStarting / CommandFinished, LIFO for nested
 * Artisan::call()). The exit code is stored as the status code.
 *
 * A top-level command is frozen at CommandFinished but finalized at terminate (or when
 * the next unit starts): the console kernel reports an uncaught exception only after
 * CommandFinished, and it must land in this command's trace.
 */
final class CommandCollector implements Collector
{
    private int $open = 0;

    public function __construct(
        private readonly Dispatcher $events,
        private readonly UnitLifecycle $lifecycle,
    ) {}

    public function register(): void
    {
        $this->events->listen(CommandStarting::class, $this->starting(...));
        $this->events->listen(CommandFinished::class, $this->finished(...));
    }

    public function starting(CommandStarting $event): void
    {
        try {
            $this->open++;
            $this->lifecycle->begin(new EntryCandidate(EntryType::Command, $event->command !== '' ? $event->command : 'artisan'));
        } catch (Throwable $e) {
            $this->lifecycle->failures()->report($e);
        }
    }

    public function finished(CommandFinished $event): void
    {
        try {
            if ($this->open === 0) {
                return;
            }
            $this->open--;

            $manager = $this->lifecycle->manager();
            if ($manager->depth() === 1) {
                $context = $manager->current();
                $context->setStatusCode($event->exitCode);
                if ($event->exitCode !== 0) {
                    $context->markFailed();
                }
            }

            $this->lifecycle->end(linger: $this->open === 0);
        } catch (Throwable $e) {
            $this->lifecycle->failures()->report($e);
        }
    }
}
