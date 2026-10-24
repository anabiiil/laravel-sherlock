<?php

declare(strict_types=1);

namespace Anabil\Tracing\Storage\Support;

use Anabil\Tracing\Contracts\ContextManager;
use Anabil\Tracing\Tracing;
use Closure;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Runs the package's own storage work with capture paused, so tracing never records
 * its own queries. Delegates to a pause(Closure) method on Tracing or the ContextManager
 * when the capture layer provides one; otherwise runs the callback directly.
 */
final class RecordingPauser
{
    /** @var (callable(Closure): mixed)|false|null null = not resolved yet, false = no pause hook */
    private mixed $pause = null;

    public function __construct(
        private readonly ?Container $container = null,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        $pause = $this->resolve();

        if ($pause === false) {
            return $callback();
        }

        $result = null;
        $pause(static function () use ($callback, &$result): mixed {
            return $result = $callback();
        });

        /** @var T $result */
        return $result;
    }

    /**
     * @return (callable(Closure): mixed)|false
     */
    private function resolve(): callable|false
    {
        if ($this->pause !== null) {
            return $this->pause;
        }

        $this->pause = false;

        if ($this->container === null) {
            return false;
        }

        foreach ([Tracing::class, ContextManager::class] as $abstract) {
            try {
                $target = $this->container->bound($abstract) ? $this->container->make($abstract) : null;
            } catch (Throwable) {
                $target = null;
            }

            $candidate = [$target, 'pause'];

            if (is_object($target) && is_callable($candidate)) {
                return $this->pause = $candidate;
            }
        }

        return false;
    }
}
