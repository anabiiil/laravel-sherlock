<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Collectors;

use Anabil\Sherlock\Context\ContextManager;
use Anabil\Sherlock\Context\FailureReporter;
use Anabil\Sherlock\Contracts\Collector;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Throwable;

/**
 * Exact N+1 relation names from Eloquent's lazy-loading prevention (opt-in, lazy_loading.detect).
 *
 * Installed once the application has booted (after the app's own providers), and only when the
 * app did not set up lazy-loading prevention itself: then Model::preventLazyLoading() is enabled
 * with a handler that records "Model::relation" into the current trace (O(1), deduplicated per
 * trace) and never throws. Eloquent only flags models hydrated as part of a collection (more than
 * one row), and after the handler returns the relation is still lazy loaded as usual
 * (HasAttributes::getRelationValue() in Laravel 11, 12 and 13), so the app's behaviour is
 * unchanged.
 *
 * An app that already uses preventLazyLoading() / handleLazyLoadingViolationUsing() keeps its
 * own setup; its handler can call Sherlock::recordLazyLoad($model, $relation).
 */
final class LazyLoadingCollector implements Collector
{
    /** The handler installed by the package (replaced, never treated as the app's, on re-boot). */
    private static ?Closure $handler = null;

    private bool $installed = false;

    public function __construct(
        private readonly Application $app,
        private readonly ContextManager $manager,
        private readonly FailureReporter $failures,
    ) {}

    public function register(): void
    {
        if (! class_exists(Model::class)) {
            return;
        }

        $this->app->booted(function (): void {
            try {
                $this->install();
            } catch (Throwable $e) {
                $this->failures->report($e);
            }
        });
    }

    /** Whether the Sherlock handler is active (false when the app manages lazy loading itself). */
    public function isInstalled(): bool
    {
        return $this->installed;
    }

    public function install(): bool
    {
        $current = self::currentHandler();
        $ours = $current !== null && $current === self::$handler;

        // The app enabled prevention or registered its own handler: never override it.
        if (! $ours && ($current !== null || Model::preventsLazyLoading())) {
            return $this->installed = false;
        }

        $manager = $this->manager;
        self::$handler = static function (Model $model, string $relation) use ($manager): void {
            self::record($manager, $model, $relation);
        };

        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(self::$handler);

        return $this->installed = true;
    }

    /** Remove the Sherlock handler (only when it is still the active one). */
    public static function uninstall(): void
    {
        if (self::$handler !== null && self::currentHandler() === self::$handler) {
            Model::handleLazyLoadingViolationUsing(null);
            Model::preventLazyLoading(false);
        }

        self::$handler = null;
    }

    /**
     * Record one lazy load of $relation on $model into the active trace. Never throws.
     */
    public static function record(ContextManager $manager, Model|string $model, string $relation): void
    {
        try {
            if (! $manager->isRecording()) {
                return;
            }

            $manager->active()?->addLazyLoad(
                is_string($model) ? $model : $model::class,
                $relation,
                static fn (): ?string => self::relatedTable($model, $relation),
            );
        } catch (Throwable) {
            // Diagnostics must never break the application's relation access.
        }
    }

    /**
     * Table of the related model (instantiates the relation object, runs no query). Called once
     * per relation per trace.
     *
     * @param  Model|string  $model  a model, or a model class name (Sherlock::recordLazyLoad())
     */
    private static function relatedTable(Model|string $model, string $relation): ?string
    {
        if (is_string($model)) {
            if (! is_subclass_of($model, Model::class)) {
                return null;
            }
            $model = new $model;
        }

        if (! method_exists($model, $relation)) {
            return null;
        }

        $instance = $model->{$relation}();

        return $instance instanceof Relation ? $instance->getRelated()->getTable() : null;
    }

    private static function currentHandler(): mixed
    {
        $read = Closure::bind(static fn (): mixed => Model::$lazyLoadingViolationCallback ?? null, null, Model::class);

        return $read === null ? null : $read();
    }
}
