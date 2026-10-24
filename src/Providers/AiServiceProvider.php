<?php

declare(strict_types=1);

namespace Anabil\Tracing\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Owns AI: AiProviderFactory (AiManager + drivers / presets), RedactingAiProvider, AiBudget, AiAnalyzer (AiAnalysisService), PromptBuilder.
 */
final class AiServiceProvider extends ServiceProvider
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
