<?php

declare(strict_types=1);

namespace Anabil\Sherlock;

use Anabil\Sherlock\Contracts\AiAnalyzer;
use Anabil\Sherlock\Contracts\AiBudget;
use Anabil\Sherlock\Contracts\AiProvider;
use Anabil\Sherlock\Contracts\AiProviderFactory;
use Anabil\Sherlock\Contracts\BottleneckClassifier;
use Anabil\Sherlock\Contracts\ContextManager;
use Anabil\Sherlock\Contracts\DigestService;
use Anabil\Sherlock\Contracts\Fingerprinter;
use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Contracts\IssueService;
use Anabil\Sherlock\Contracts\NotificationDispatcher;
use Anabil\Sherlock\Contracts\PersistenceStrategy;
use Anabil\Sherlock\Contracts\PlanInterpreter;
use Anabil\Sherlock\Contracts\QueryNormalizer;
use Anabil\Sherlock\Contracts\RecordingPolicy;
use Anabil\Sherlock\Contracts\Redactor as RedactorContract;
use Anabil\Sherlock\Contracts\ServerProbe;
use Anabil\Sherlock\Contracts\SourceLocator;
use Anabil\Sherlock\Contracts\TraceAnalyzer;
use Anabil\Sherlock\Contracts\TraceRecorder;
use Anabil\Sherlock\Contracts\TraceRepository;
use Anabil\Sherlock\Contracts\WatchWindow;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Pipeline\DefaultTraceRecorder;
use Anabil\Sherlock\Privacy\Redactor;
use Anabil\Sherlock\Providers\AiServiceProvider;
use Anabil\Sherlock\Providers\AnalysisServiceProvider;
use Anabil\Sherlock\Providers\CaptureServiceProvider;
use Anabil\Sherlock\Providers\ConsoleServiceProvider;
use Anabil\Sherlock\Providers\DashboardServiceProvider;
use Anabil\Sherlock\Providers\DigestServiceProvider;
use Anabil\Sherlock\Providers\NotificationServiceProvider;
use Anabil\Sherlock\Providers\StorageServiceProvider;
use Anabil\Sherlock\Support\BacktraceSourceLocator;
use Anabil\Sherlock\Support\HashFingerprinter;
use Anabil\Sherlock\Support\Null\NullAiAnalyzer;
use Anabil\Sherlock\Support\Null\NullAiBudget;
use Anabil\Sherlock\Support\Null\NullAiProvider;
use Anabil\Sherlock\Support\Null\NullAiProviderFactory;
use Anabil\Sherlock\Support\Null\NullBottleneckClassifier;
use Anabil\Sherlock\Support\Null\NullContextManager;
use Anabil\Sherlock\Support\Null\NullDigestService;
use Anabil\Sherlock\Support\Null\NullIssueRepository;
use Anabil\Sherlock\Support\Null\NullIssueService;
use Anabil\Sherlock\Support\Null\NullNotificationDispatcher;
use Anabil\Sherlock\Support\Null\NullPersistenceStrategy;
use Anabil\Sherlock\Support\Null\NullPlanInterpreter;
use Anabil\Sherlock\Support\Null\NullRecordingPolicy;
use Anabil\Sherlock\Support\Null\NullTraceAnalyzer;
use Anabil\Sherlock\Support\Null\NullTraceRepository;
use Anabil\Sherlock\Support\Null\NullWatchWindow;
use Anabil\Sherlock\Support\SqlQueryNormalizer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Core wiring only: config, publishing, core services and Null Object defaults.
 * Every feature lives in its own sub-provider (src/Providers), which rebinds the
 * contracts it implements.
 */
final class SherlockServiceProvider extends ServiceProvider
{
    /** @var list<class-string<ServiceProvider>> */
    public const FEATURE_PROVIDERS = [
        CaptureServiceProvider::class,
        AnalysisServiceProvider::class,
        StorageServiceProvider::class,
        NotificationServiceProvider::class,
        AiServiceProvider::class,
        DigestServiceProvider::class,
        DashboardServiceProvider::class,
        ConsoleServiceProvider::class,
    ];

    /**
     * Null Object defaults; feature providers override them with real implementations.
     *
     * @var array<class-string, class-string>
     */
    public const NULL_BINDINGS = [
        ContextManager::class => NullContextManager::class,
        RecordingPolicy::class => NullRecordingPolicy::class,
        WatchWindow::class => NullWatchWindow::class,
        TraceAnalyzer::class => NullTraceAnalyzer::class,
        BottleneckClassifier::class => NullBottleneckClassifier::class,
        PlanInterpreter::class => NullPlanInterpreter::class,
        PersistenceStrategy::class => NullPersistenceStrategy::class,
        TraceRepository::class => NullTraceRepository::class,
        IssueRepository::class => NullIssueRepository::class,
        IssueService::class => NullIssueService::class,
        NotificationDispatcher::class => NullNotificationDispatcher::class,
        AiProvider::class => NullAiProvider::class,
        AiProviderFactory::class => NullAiProviderFactory::class,
        AiAnalyzer::class => NullAiAnalyzer::class,
        AiBudget::class => NullAiBudget::class,
        DigestService::class => NullDigestService::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sherlock.php', 'sherlock');

        $this->registerCoreServices();

        foreach (self::NULL_BINDINGS as $contract => $implementation) {
            $this->app->singletonIf($contract, $implementation);
        }

        foreach (self::FEATURE_PROVIDERS as $provider) {
            $this->app->register($provider);
        }
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/sherlock.php' => $this->app->configPath('sherlock.php'),
        ], 'sherlock-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
        ], 'sherlock-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/sherlock'),
        ], 'sherlock-views');
    }

    private function registerCoreServices(): void
    {
        $this->app->singleton(Sherlock::class);

        $this->app->singleton(RedactorContract::class, static function (Application $app): Redactor {
            $config = $app->make(Repository::class);

            return Redactor::make(
                self::iterable($config->get('sherlock.privacy.sensitive_keys')),
                self::iterable($config->get('sherlock.privacy.patterns')),
            );
        });

        $this->app->singleton(QueryNormalizer::class, SqlQueryNormalizer::class);
        $this->app->singleton(Fingerprinter::class, HashFingerprinter::class);

        $this->app->singleton(SourceLocator::class, static function (Application $app): BacktraceSourceLocator {
            $limit = $app->make(Repository::class)->get('sherlock.capture.backtrace_limit', 30);

            return new BacktraceSourceLocator($app->basePath(), is_numeric($limit) ? (int) $limit : 30);
        });

        $this->app->singleton(TraceRecorder::class, static function (Application $app): DefaultTraceRecorder {
            $config = $app->make(Repository::class);
            $storeBindings = $config->get('sherlock.privacy.store_bindings', 'auto');

            return new DefaultTraceRecorder(
                $app->make(TraceAnalyzer::class),
                $app->make(PersistenceStrategy::class),
                Severity::fromConfig($config->get('sherlock.persist_min_severity')),
                is_string($storeBindings) ? $storeBindings : 'auto',
                $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null,
                $app->bound(ServerProbe::class) ? $app->make(ServerProbe::class) : null,
                is_numeric($warning = $config->get('sherlock.server.load_per_core_warning')) ? (float) $warning : 1.5,
            );
        });
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function iterable(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
