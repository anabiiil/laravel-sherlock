<?php

declare(strict_types=1);

namespace Anabil\Tracing\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Owns analysis: TraceAnalyzer (analyzer pipeline), analyzers from config("tracing.analyzers"), BottleneckClassifier, Explainers and PlanInterpreter.
 */
final class AnalysisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
