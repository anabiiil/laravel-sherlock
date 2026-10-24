<?php

declare(strict_types=1);

namespace Anabil\Tracing\Context;

use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A failure inside tracing must never break the host request / job / command. Every
 * listener body catches Throwable and lands here: the first failure of the process is
 * logged once at warning level (below the captured level, and with capture paused so it
 * cannot feed back into a trace); later ones are only counted.
 */
final class FailureReporter
{
    private int $failures = 0;

    public function __construct(
        private readonly Container $container,
        private readonly ContextManager $manager,
    ) {}

    public function report(Throwable $e): void
    {
        if ($this->failures++ > 0) {
            return;
        }

        try {
            $this->manager->pause(function () use ($e): void {
                if (! $this->container->bound('log')) {
                    return;
                }

                $logger = $this->container->make('log');
                if ($logger instanceof LoggerInterface) {
                    $logger->warning('[tracing] capture failed and was disabled for this unit: '.$e->getMessage(), [
                        'exception_class' => $e::class,
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);
                }
            });
        } catch (Throwable) {
            // Never let reporting itself escape.
        }
    }

    public function count(): int
    {
        return $this->failures;
    }
}
