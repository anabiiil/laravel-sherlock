<?php

declare(strict_types=1);

namespace Anabil\Sherlock;

use Anabil\Sherlock\Collectors\LazyLoadingCollector;
use Anabil\Sherlock\Context\ContextManager as RecordingContextManager;
use Anabil\Sherlock\Contracts\AiProviderFactory;
use Anabil\Sherlock\Contracts\ContextManager;
use Anabil\Sherlock\Contracts\Redactor;
use Anabil\Sherlock\Contracts\WatchWindow;
use Anabil\Sherlock\Data\WatchState;
use Anabil\Sherlock\Enums\SherlockMode;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;

/**
 * Main service behind the Sherlock facade (user-facing API only).
 * Services are resolved lazily so calling the facade costs nothing when unused.
 */
final class Sherlock
{
    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
    ) {}

    public function mode(): SherlockMode
    {
        return SherlockMode::fromConfig($this->config->get('sherlock.mode'));
    }

    public function enabled(): bool
    {
        return $this->mode()->isEnabled();
    }

    public function isRecording(): bool
    {
        return $this->contexts()->isRecording();
    }

    /** Attach a tag to the current trace (no-op when not recording). String values are redacted. */
    public function tag(string $key, string|int|float|bool|null $value): void
    {
        $context = $this->contexts()->current();
        if (! $context->isActive()) {
            return;
        }

        $redactor = $this->container->make(Redactor::class);
        if ($redactor->isSensitiveKey($key)) {
            $value = Redactor::REDACTED;
        } elseif (is_string($value)) {
            $value = $redactor->redactString($value);
        }

        $context->tag($key, $value);
    }

    /**
     * Record a lazy-loaded relation in the current trace (named in N+1 findings). For apps that use
     * Model::preventLazyLoading() with their own handler:
     *
     *     Model::handleLazyLoadingViolationUsing(function ($model, $relation) {
     *         Sherlock::recordLazyLoad($model, $relation);
     *         // ... the app's own handling
     *     });
     *
     * O(1), deduplicated per trace, never throws; a no-op when not recording.
     */
    public function recordLazyLoad(Model|string $model, string $relation): void
    {
        $manager = $this->contexts();
        if ($manager instanceof RecordingContextManager) {
            LazyLoadingCollector::record($manager, $model, $relation);
        }
    }

    /**
     * Run $callback without recording anything it does (queries, cache, logs, HTTP calls).
     * The package uses it for its own work; works (as a plain call) when tracing is off.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function pause(Closure $callback): mixed
    {
        return $this->contexts()->pause($callback);
    }

    /**
     * Register a custom AI driver: Sherlock::extendAi('name', fn ($app, array $config) => new MyDriver).
     */
    public function extendAi(string $driver, Closure $factory): void
    {
        $this->container->make(AiProviderFactory::class)->extend($driver, $factory);
    }

    public function watch(int $minutes, ?string $routePattern = null, int|string|null $userId = null): WatchState
    {
        return $this->container->make(WatchWindow::class)->start($minutes, $routePattern, $userId);
    }

    public function stopWatching(): void
    {
        $this->container->make(WatchWindow::class)->stop();
    }

    /** The active watch window, or null. */
    public function watching(): ?WatchState
    {
        return $this->container->make(WatchWindow::class)->current();
    }

    private function contexts(): ContextManager
    {
        return $this->container->make(ContextManager::class);
    }
}
