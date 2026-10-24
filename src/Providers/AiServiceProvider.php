<?php

declare(strict_types=1);

namespace Anabil\Tracing\Providers;

use Anabil\Tracing\Ai\AiManager;
use Anabil\Tracing\Ai\CacheAiBudget;
use Anabil\Tracing\Ai\DefaultAiAnalyzer;
use Anabil\Tracing\Ai\PromptBuilder;
use Anabil\Tracing\Console\AnalyzeCommand;
use Anabil\Tracing\Contracts\AiAnalyzer;
use Anabil\Tracing\Contracts\AiBudget;
use Anabil\Tracing\Contracts\AiProvider;
use Anabil\Tracing\Contracts\AiProviderFactory;
use Anabil\Tracing\Contracts\IssueRepository;
use Anabil\Tracing\Contracts\Redactor;
use Anabil\Tracing\Tracing;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Owns AI: AiProviderFactory (AiManager + drivers / presets), RedactingAiProvider, AiBudget, AiAnalyzer (AiAnalysisService), PromptBuilder.
 * Nothing here runs in the request path: analysis happens in AnalyzeIssueJob or tracing:analyze.
 */
final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiManager::class, static fn (Application $app): AiManager => new AiManager(
            $app,
            $app->make(Repository::class),
            $app->make(Redactor::class),
        ));
        $this->app->singleton(AiProviderFactory::class, static fn (Application $app): AiManager => $app->make(AiManager::class));

        // Always the redacting decorator around the configured driver.
        $this->app->bind(AiProvider::class, static fn (Application $app): AiProvider => $app->make(AiProviderFactory::class)->driver());

        $this->app->singleton(AiBudget::class, static function (Application $app): CacheAiBudget {
            $config = $app->make(Repository::class);
            $limit = $config->get('tracing.ai.daily_limit', 50);
            $timezone = $config->get('app.timezone', 'UTC');

            return new CacheAiBudget(
                self::cache($app),
                // null = unlimited; see CacheAiBudget for the semantics.
                $limit === null ? -1 : (is_numeric($limit) ? (int) $limit : 50),
                is_string($timezone) && $timezone !== '' ? $timezone : 'UTC',
            );
        });

        $this->app->singleton(PromptBuilder::class, static fn (Application $app): PromptBuilder => new PromptBuilder(
            $app->make(Repository::class),
            $app->make(Redactor::class),
            $app->bound('db') ? $app->make(ConnectionResolverInterface::class) : null,
            $app->basePath(),
        ));

        $this->app->singleton(AiAnalyzer::class, static fn (Application $app): DefaultAiAnalyzer => new DefaultAiAnalyzer(
            $app->make(IssueRepository::class),
            $app->make(AiProviderFactory::class),
            $app->make(AiBudget::class),
            $app->make(PromptBuilder::class),
            $app->make(Repository::class),
            self::cache($app),
            $app->make(Tracing::class),
            $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null,
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([AnalyzeCommand::class]);
        }
    }

    private static function cache(Application $app): Cache
    {
        $store = $app->make(Repository::class)->get('tracing.ai.budget_store');

        return $app->make(CacheFactory::class)->store(is_string($store) && $store !== '' ? $store : null);
    }
}
