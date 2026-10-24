<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Providers;

use Anabil\Sherlock\Ai\AiManager;
use Anabil\Sherlock\Ai\CacheAiBudget;
use Anabil\Sherlock\Ai\DefaultAiAnalyzer;
use Anabil\Sherlock\Ai\PromptBuilder;
use Anabil\Sherlock\Console\AnalyzeCommand;
use Anabil\Sherlock\Contracts\AiAnalyzer;
use Anabil\Sherlock\Contracts\AiBudget;
use Anabil\Sherlock\Contracts\AiProvider;
use Anabil\Sherlock\Contracts\AiProviderFactory;
use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Contracts\Redactor;
use Anabil\Sherlock\Contracts\TraceRepository;
use Anabil\Sherlock\Sherlock;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Owns AI: AiProviderFactory (AiManager + drivers / presets), RedactingAiProvider, AiBudget, AiAnalyzer (AiAnalysisService), PromptBuilder.
 * Nothing here runs in the request path: analysis happens in AnalyzeIssueJob or sherlock:analyze.
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
            $limit = $config->get('sherlock.ai.daily_limit', 50);
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
            $app->make(TraceRepository::class),
        ));

        $this->app->singleton(AiAnalyzer::class, static fn (Application $app): DefaultAiAnalyzer => new DefaultAiAnalyzer(
            $app->make(IssueRepository::class),
            $app->make(AiProviderFactory::class),
            $app->make(AiBudget::class),
            $app->make(PromptBuilder::class),
            $app->make(Repository::class),
            self::cache($app),
            $app->make(Sherlock::class),
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
        $store = $app->make(Repository::class)->get('sherlock.ai.budget_store');

        return $app->make(CacheFactory::class)->store(is_string($store) && $store !== '' ? $store : null);
    }
}
