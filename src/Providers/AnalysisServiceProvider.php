<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Providers;

use Anabil\Sherlock\Analysis\Analyzers\CacheMissRateAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\DuplicateQueryAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\ExceptionAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\ExcessiveQueriesAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\HighMemoryAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\MemoryLeakAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\MissingIndexAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\NPlusOneAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\QueueWaitAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\ServerLoadAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\SlowEntryAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\SlowExternalCallAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\SlowQueryAnalyzer;
use Anabil\Sherlock\Analysis\DefaultBottleneckClassifier;
use Anabil\Sherlock\Analysis\Explain\DefaultPlanInterpreter;
use Anabil\Sherlock\Analysis\Explain\ExplainService;
use Anabil\Sherlock\Analysis\Explain\MySqlExplainer;
use Anabil\Sherlock\Analysis\Explain\PostgresExplainer;
use Anabil\Sherlock\Analysis\Explain\SqliteExplainer;
use Anabil\Sherlock\Analysis\TraceAnalysisPipeline;
use Anabil\Sherlock\Contracts\Analyzer;
use Anabil\Sherlock\Contracts\BottleneckClassifier;
use Anabil\Sherlock\Contracts\Explainer;
use Anabil\Sherlock\Contracts\Fingerprinter;
use Anabil\Sherlock\Contracts\PlanInterpreter;
use Anabil\Sherlock\Contracts\QueryNormalizer;
use Anabil\Sherlock\Contracts\SourceLocator;
use Anabil\Sherlock\Contracts\TraceAnalyzer;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Owns analysis: TraceAnalyzer (analyzer pipeline), analyzers from config("sherlock.analyzers"),
 * BottleneckClassifier, Explainers (tagged "sherlock.explainers") and PlanInterpreter.
 * Overrides the Null Object defaults of the core provider.
 */
final class AnalysisServiceProvider extends ServiceProvider
{
    public const EXPLAINER_TAG = 'sherlock.explainers';

    public function register(): void
    {
        $this->app->singleton(PlanInterpreter::class, static fn (Application $app): DefaultPlanInterpreter => new DefaultPlanInterpreter(
            self::int($app, 'sherlock.thresholds.missing_index.warning_rows', 1000),
            self::int($app, 'sherlock.thresholds.missing_index.critical_rows', 100000),
        ));

        $this->app->singleton(BottleneckClassifier::class, static fn (Application $app): DefaultBottleneckClassifier => new DefaultBottleneckClassifier(
            self::classifierRules($app),
        ));

        $this->registerAnalyzers();
        $this->registerExplain();

        $this->app->singleton(TraceAnalyzer::class, static fn (Application $app): TraceAnalysisPipeline => new TraceAnalysisPipeline(
            $app->make(QueryNormalizer::class),
            $app->make(Fingerprinter::class),
            self::analyzers($app),
            $app->make(BottleneckClassifier::class),
            $app->make(ExplainService::class),
            self::logger($app),
        ));
    }

    /**
     * Built-in analyzers read their thresholds from config; custom analyzers in
     * config("sherlock.analyzers") are resolved through the container.
     */
    private function registerAnalyzers(): void
    {
        $fp = static fn (Application $app): Fingerprinter => $app->make(Fingerprinter::class);

        $this->app->bind(SlowQueryAnalyzer::class, static fn (Application $app): SlowQueryAnalyzer => new SlowQueryAnalyzer(
            $fp($app),
            self::float($app, 'sherlock.thresholds.slow_query.warning_ms', 100),
            self::float($app, 'sherlock.thresholds.slow_query.critical_ms', 1000),
        ));

        $this->app->bind(NPlusOneAnalyzer::class, static fn (Application $app): NPlusOneAnalyzer => new NPlusOneAnalyzer(
            $fp($app),
            self::int($app, 'sherlock.thresholds.n_plus_one.warning_count', 5),
            self::int($app, 'sherlock.thresholds.n_plus_one.critical_count', 20),
        ));

        $this->app->bind(DuplicateQueryAnalyzer::class, static fn (Application $app): DuplicateQueryAnalyzer => new DuplicateQueryAnalyzer(
            $fp($app),
            self::int($app, 'sherlock.thresholds.duplicate_query.min_count', 2),
        ));

        $this->app->bind(ExcessiveQueriesAnalyzer::class, static fn (Application $app): ExcessiveQueriesAnalyzer => new ExcessiveQueriesAnalyzer(
            $fp($app),
            self::int($app, 'sherlock.thresholds.excessive_queries.warning_count', 50),
            self::int($app, 'sherlock.thresholds.excessive_queries.critical_count', 200),
        ));

        $this->app->bind(SlowEntryAnalyzer::class, static fn (Application $app): SlowEntryAnalyzer => new SlowEntryAnalyzer(
            $fp($app),
            self::array($app, 'sherlock.thresholds.entries') ?: SlowEntryAnalyzer::DEFAULTS,
        ));

        $this->app->bind(HighMemoryAnalyzer::class, static fn (Application $app): HighMemoryAnalyzer => new HighMemoryAnalyzer(
            $fp($app),
            self::float($app, 'sherlock.thresholds.memory.warning_mb', 128),
            self::float($app, 'sherlock.thresholds.memory.critical_mb', 512),
        ));

        $this->app->bind(SlowExternalCallAnalyzer::class, static fn (Application $app): SlowExternalCallAnalyzer => new SlowExternalCallAnalyzer(
            $fp($app),
            self::float($app, 'sherlock.thresholds.slow_http.warning_ms', 1000),
            self::float($app, 'sherlock.thresholds.slow_http.critical_ms', 5000),
        ));

        $this->app->bind(CacheMissRateAnalyzer::class, static fn (Application $app): CacheMissRateAnalyzer => new CacheMissRateAnalyzer(
            $fp($app),
            self::int($app, 'sherlock.thresholds.cache_miss_rate.min_lookups', 10),
            self::float($app, 'sherlock.thresholds.cache_miss_rate.info_ratio', 0.5),
            self::float($app, 'sherlock.thresholds.cache_miss_rate.warning_ratio', 0.8),
        ));

        $this->app->bind(ExceptionAnalyzer::class, static fn (Application $app): ExceptionAnalyzer => new ExceptionAnalyzer(
            $fp($app),
            $app->make(SourceLocator::class),
            self::array($app, 'sherlock.thresholds.log_levels') ?: ExceptionAnalyzer::DEFAULT_LOG_LEVELS,
            max(0.0, self::float($app, 'sherlock.impact.exception_ms', 0)),
        ));

        $this->app->bind(MissingIndexAnalyzer::class, static fn (Application $app): MissingIndexAnalyzer => new MissingIndexAnalyzer($fp($app)));

        $this->app->bind(ServerLoadAnalyzer::class, static fn (Application $app): ServerLoadAnalyzer => new ServerLoadAnalyzer(
            $fp($app),
            self::float($app, 'sherlock.server.load_per_core_warning', 1.5),
            self::float($app, 'sherlock.server.load_per_core_critical', 3.0),
            self::array($app, 'sherlock.thresholds.entries') ?: SlowEntryAnalyzer::DEFAULTS,
        ));

        $this->app->bind(QueueWaitAnalyzer::class, static fn (Application $app): QueueWaitAnalyzer => new QueueWaitAnalyzer(
            $fp($app),
            self::float($app, 'sherlock.thresholds.queue_wait.warning_ms', 30000),
            self::float($app, 'sherlock.thresholds.queue_wait.critical_ms', 300000),
        ));

        $this->app->bind(MemoryLeakAnalyzer::class, static fn (Application $app): MemoryLeakAnalyzer => new MemoryLeakAnalyzer(
            $fp($app),
            self::float($app, 'sherlock.memory_leak.critical_mb', 128),
        ));
    }

    private function registerExplain(): void
    {
        $timeout = static fn (Application $app): int => self::int($app, 'sherlock.explain.timeout_ms', 1000);

        $this->app->singleton(MySqlExplainer::class, static fn (Application $app): MySqlExplainer => new MySqlExplainer($app->make(ConnectionResolverInterface::class), $timeout($app)));
        $this->app->singleton(PostgresExplainer::class, static fn (Application $app): PostgresExplainer => new PostgresExplainer($app->make(ConnectionResolverInterface::class), $timeout($app)));
        $this->app->singleton(SqliteExplainer::class, static fn (Application $app): SqliteExplainer => new SqliteExplainer($app->make(ConnectionResolverInterface::class), $timeout($app)));
        $this->app->tag([MySqlExplainer::class, PostgresExplainer::class, SqliteExplainer::class], self::EXPLAINER_TAG);

        $this->app->singleton(ExplainService::class, static function (Application $app): ExplainService {
            /** @var iterable<Explainer> $explainers */
            $explainers = $app->tagged(self::EXPLAINER_TAG);

            return new ExplainService(
                $explainers,
                $app->make(PlanInterpreter::class),
                self::cacheStore($app),
                self::explainEnabled($app),
                self::explainMinQueryMs($app),
                self::int($app, 'sherlock.explain.cache_minutes', 1440) * 60,
                self::int($app, 'sherlock.explain.max_per_trace', 3),
            );
        });
    }

    /**
     * Entries are class names (enabled) or class => bool (false disables it).
     * Missing classes or classes not implementing Analyzer are skipped.
     *
     * @return list<Analyzer>
     */
    private static function analyzers(Application $app): array
    {
        $analyzers = [];

        foreach (self::array($app, 'sherlock.analyzers') as $key => $value) {
            [$class, $enabled] = is_string($key) ? [$key, filter_var($value, FILTER_VALIDATE_BOOL)] : [$value, true];

            if (! $enabled || ! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Analyzer::class)) {
                continue;
            }

            try {
                $analyzer = $app->make($class);
            } catch (Throwable $e) {
                self::logger($app)?->warning('[sherlock] analyzer '.$class.' could not be resolved: '.$e->getMessage());

                continue;
            }

            if ($analyzer instanceof Analyzer) {
                $analyzers[] = $analyzer;
            }
        }

        return $analyzers;
    }

    /**
     * sherlock.bottleneck plus the server / queue thresholds the classifier uses.
     *
     * @return array<array-key, mixed>
     */
    private static function classifierRules(Application $app): array
    {
        $rules = self::array($app, 'sherlock.bottleneck');
        $config = $app->make(Config::class);

        foreach ([
            'server_load' => 'sherlock.server.load_per_core_warning',
            'cpu_bound' => 'sherlock.server.cpu_bound_ratio',
            'waiting' => 'sherlock.server.waiting_ratio',
            'queue_wait_ms' => 'sherlock.thresholds.queue_wait.warning_ms',
        ] as $rule => $key) {
            $value = $config->get($key);
            if (! isset($rules[$rule]) && is_numeric($value)) {
                $rules[$rule] = (float) $value;
            }
        }

        return $rules;
    }

    /**
     * Minimum query time for EXPLAIN: explain.min_query_ms, else the slow-query warning threshold.
     * Also used by capture to decide which lightweight queries keep EXPLAIN-only bindings.
     */
    public static function explainMinQueryMs(Application $app): float
    {
        $minMs = $app->make(Config::class)->get('sherlock.explain.min_query_ms');

        return is_numeric($minMs) ? (float) $minMs : self::float($app, 'sherlock.thresholds.slow_query.warning_ms', 100);
    }

    public static function explainEnabled(Application $app): bool
    {
        $config = $app->make(Config::class);
        if (! filter_var($config->get('sherlock.explain.enabled', true), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        $environments = $config->get('sherlock.explain.environments');
        if (is_string($environments)) {
            $environments = array_filter(array_map('trim', explode(',', $environments)));
        }

        return ! is_array($environments) || $environments === [] || in_array($app->environment(), $environments, true);
    }

    private static function cacheStore(Application $app): ?CacheRepository
    {
        if (! $app->bound(CacheFactory::class) && ! $app->bound('cache')) {
            return null;
        }

        try {
            $store = $app->make(Config::class)->get('sherlock.explain.cache_store');

            return $app->make(CacheFactory::class)->store(is_string($store) && $store !== '' ? $store : null);
        } catch (Throwable) {
            return null;
        }
    }

    private static function logger(Application $app): ?LoggerInterface
    {
        return $app->bound(LoggerInterface::class) || $app->bound('log') ? $app->make(LoggerInterface::class) : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function array(Application $app, string $key): array
    {
        $value = $app->make(Config::class)->get($key);

        return is_array($value) ? $value : [];
    }

    private static function int(Application $app, string $key, int $default): int
    {
        $value = $app->make(Config::class)->get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    private static function float(Application $app, string $key, float $default): float
    {
        $value = $app->make(Config::class)->get($key);

        return is_numeric($value) ? (float) $value : $default;
    }
}
