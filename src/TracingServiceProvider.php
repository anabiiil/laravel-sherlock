<?php

declare(strict_types=1);

namespace Anabil\Tracing;

use Anabil\Tracing\Contracts\AiAnalyzer;
use Anabil\Tracing\Contracts\AiBudget;
use Anabil\Tracing\Contracts\AiProvider;
use Anabil\Tracing\Contracts\AiProviderFactory;
use Anabil\Tracing\Contracts\BottleneckClassifier;
use Anabil\Tracing\Contracts\ContextManager;
use Anabil\Tracing\Contracts\DigestService;
use Anabil\Tracing\Contracts\Fingerprinter;
use Anabil\Tracing\Contracts\IssueRepository;
use Anabil\Tracing\Contracts\IssueService;
use Anabil\Tracing\Contracts\NotificationDispatcher;
use Anabil\Tracing\Contracts\PersistenceStrategy;
use Anabil\Tracing\Contracts\PlanInterpreter;
use Anabil\Tracing\Contracts\QueryNormalizer;
use Anabil\Tracing\Contracts\RecordingPolicy;
use Anabil\Tracing\Contracts\Redactor as RedactorContract;
use Anabil\Tracing\Contracts\SourceLocator;
use Anabil\Tracing\Contracts\TraceAnalyzer;
use Anabil\Tracing\Contracts\TraceRecorder;
use Anabil\Tracing\Contracts\TraceRepository;
use Anabil\Tracing\Contracts\WatchWindow;
use Anabil\Tracing\Enums\Severity;
use Anabil\Tracing\Pipeline\DefaultTraceRecorder;
use Anabil\Tracing\Privacy\Redactor;
use Anabil\Tracing\Providers\AiServiceProvider;
use Anabil\Tracing\Providers\AnalysisServiceProvider;
use Anabil\Tracing\Providers\CaptureServiceProvider;
use Anabil\Tracing\Providers\ConsoleServiceProvider;
use Anabil\Tracing\Providers\DashboardServiceProvider;
use Anabil\Tracing\Providers\DigestServiceProvider;
use Anabil\Tracing\Providers\NotificationServiceProvider;
use Anabil\Tracing\Providers\StorageServiceProvider;
use Anabil\Tracing\Support\BacktraceSourceLocator;
use Anabil\Tracing\Support\HashFingerprinter;
use Anabil\Tracing\Support\Null\NullAiAnalyzer;
use Anabil\Tracing\Support\Null\NullAiBudget;
use Anabil\Tracing\Support\Null\NullAiProvider;
use Anabil\Tracing\Support\Null\NullAiProviderFactory;
use Anabil\Tracing\Support\Null\NullBottleneckClassifier;
use Anabil\Tracing\Support\Null\NullContextManager;
use Anabil\Tracing\Support\Null\NullDigestService;
use Anabil\Tracing\Support\Null\NullIssueRepository;
use Anabil\Tracing\Support\Null\NullIssueService;
use Anabil\Tracing\Support\Null\NullNotificationDispatcher;
use Anabil\Tracing\Support\Null\NullPersistenceStrategy;
use Anabil\Tracing\Support\Null\NullPlanInterpreter;
use Anabil\Tracing\Support\Null\NullRecordingPolicy;
use Anabil\Tracing\Support\Null\NullTraceAnalyzer;
use Anabil\Tracing\Support\Null\NullTraceRepository;
use Anabil\Tracing\Support\Null\NullWatchWindow;
use Anabil\Tracing\Support\SqlQueryNormalizer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Core wiring only: config, publishing, core services and Null Object defaults.
 * Every feature lives in its own sub-provider (src/Providers), which rebinds the
 * contracts it implements.
 */
final class TracingServiceProvider extends ServiceProvider
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
        $this->mergeConfigFrom(__DIR__.'/../config/tracing.php', 'tracing');

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
            __DIR__.'/../config/tracing.php' => $this->app->configPath('tracing.php'),
        ], 'tracing-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
        ], 'tracing-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/tracing'),
        ], 'tracing-views');
    }

    private function registerCoreServices(): void
    {
        $this->app->singleton(Tracing::class);

        $this->app->singleton(RedactorContract::class, static function (Application $app): Redactor {
            $config = $app->make(Repository::class);

            return Redactor::make(
                self::iterable($config->get('tracing.privacy.sensitive_keys')),
                self::iterable($config->get('tracing.privacy.patterns')),
            );
        });

        $this->app->singleton(QueryNormalizer::class, SqlQueryNormalizer::class);
        $this->app->singleton(Fingerprinter::class, HashFingerprinter::class);

        $this->app->singleton(SourceLocator::class, static function (Application $app): BacktraceSourceLocator {
            $limit = $app->make(Repository::class)->get('tracing.capture.backtrace_limit', 30);

            return new BacktraceSourceLocator($app->basePath(), is_numeric($limit) ? (int) $limit : 30);
        });

        $this->app->singleton(TraceRecorder::class, static function (Application $app): DefaultTraceRecorder {
            $config = $app->make(Repository::class);
            $storeBindings = $config->get('tracing.privacy.store_bindings', 'auto');

            return new DefaultTraceRecorder(
                $app->make(TraceAnalyzer::class),
                $app->make(PersistenceStrategy::class),
                Severity::fromConfig($config->get('tracing.persist_min_severity')),
                is_string($storeBindings) ? $storeBindings : 'auto',
                $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null,
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
