<?php

declare(strict_types=1);

namespace Anabil\Tracing\Providers;

use Anabil\Tracing\Analysis\Analyzers\CacheMissRateAnalyzer;
use Anabil\Tracing\Analysis\Analyzers\DuplicateQueryAnalyzer;
use Anabil\Tracing\Analysis\Analyzers\ExceptionAnalyzer;
use Anabil\Tracing\Analysis\Analyzers\ExcessiveQueriesAnalyzer;
use Anabil\Tracing\Analysis\Analyzers\HighMemoryAnalyzer;
use Anabil\Tracing\Analysis\Analyzers\MissingIndexAnalyzer;
use Anabil\Tracing\Analysis\Analyzers\NPlusOneAnalyzer;
use Anabil\Tracing\Analysis\Analyzers\SlowEntryAnalyzer;
use Anabil\Tracing\Analysis\Analyzers\SlowExternalCallAnalyzer;
use Anabil\Tracing\Analysis\Analyzers\SlowQueryAnalyzer;
use Anabil\Tracing\Analysis\DefaultBottleneckClassifier;
use Anabil\Tracing\Analysis\Explain\DefaultPlanInterpreter;
use Anabil\Tracing\Analysis\Explain\ExplainService;
use Anabil\Tracing\Analysis\Explain\MySqlExplainer;
use Anabil\Tracing\Analysis\Explain\PostgresExplainer;
use Anabil\Tracing\Analysis\Explain\SqliteExplainer;
use Anabil\Tracing\Analysis\TraceAnalysisPipeline;
use Anabil\Tracing\Contracts\Analyzer;
use Anabil\Tracing\Contracts\BottleneckClassifier;
use Anabil\Tracing\Contracts\Explainer;
use Anabil\Tracing\Contracts\Fingerprinter;
use Anabil\Tracing\Contracts\PlanInterpreter;
use Anabil\Tracing\Contracts\QueryNormalizer;
use Anabil\Tracing\Contracts\SourceLocator;
use Anabil\Tracing\Contracts\TraceAnalyzer;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Owns analysis: TraceAnalyzer (analyzer pipeline), analyzers from config("tracing.analyzers"),
 * BottleneckClassifier, Explainers (tagged "tracing.explainers") and PlanInterpreter.
 * Overrides the Null Object defaults of the core provider.
 */
final class AnalysisServiceProvider extends ServiceProvider
{
    public const EXPLAINER_TAG = 'tracing.explainers';

    public function register(): void
    {
        $this->app->singleton(PlanInterpreter::class, static fn (Application $app): DefaultPlanInterpreter => new DefaultPlanInterpreter(
            self::int($app, 'tracing.thresholds.missing_index.warning_rows', 1000),
            self::int($app, 'tracing.thresholds.missing_index.critical_rows', 100000),
        ));

        $this->app->singleton(BottleneckClassifier::class, static fn (Application $app): DefaultBottleneckClassifier => new DefaultBottleneckClassifier(
            self::array($app, 'tracing.bottleneck'),
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
     * config("tracing.analyzers") are resolved through the container.
     */
    private function registerAnalyzers(): void
    {
        $fp = static fn (Application $app): Fingerprinter => $app->make(Fingerprinter::class);

        $this->app->bind(SlowQueryAnalyzer::class, static fn (Application $app): SlowQueryAnalyzer => new SlowQueryAnalyzer(
            $fp($app),
            self::float($app, 'tracing.thresholds.slow_query.warning_ms', 100),
            self::float($app, 'tracing.thresholds.slow_query.critical_ms', 1000),
        ));

        $this->app->bind(NPlusOneAnalyzer::class, static fn (Application $app): NPlusOneAnalyzer => new NPlusOneAnalyzer(
            $fp($app),
            self::int($app, 'tracing.thresholds.n_plus_one.warning_count', 5),
            self::int($app, 'tracing.thresholds.n_plus_one.critical_count', 20),
        ));

        $this->app->bind(DuplicateQueryAnalyzer::class, static fn (Application $app): DuplicateQueryAnalyzer => new DuplicateQueryAnalyzer(
            $fp($app),
            self::int($app, 'tracing.thresholds.duplicate_query.min_count', 2),
        ));

        $this->app->bind(ExcessiveQueriesAnalyzer::class, static fn (Application $app): ExcessiveQueriesAnalyzer => new ExcessiveQueriesAnalyzer(
            $fp($app),
            self::int($app, 'tracing.thresholds.excessive_queries.warning_count', 50),
            self::int($app, 'tracing.thresholds.excessive_queries.critical_count', 200),
        ));

        $this->app->bind(SlowEntryAnalyzer::class, static fn (Application $app): SlowEntryAnalyzer => new SlowEntryAnalyzer(
            $fp($app),
            self::array($app, 'tracing.thresholds.entries') ?: SlowEntryAnalyzer::DEFAULTS,
        ));

        $this->app->bind(HighMemoryAnalyzer::class, static fn (Application $app): HighMemoryAnalyzer => new HighMemoryAnalyzer(
            $fp($app),
            self::float($app, 'tracing.thresholds.memory.warning_mb', 128),
            self::float($app, 'tracing.thresholds.memory.critical_mb', 512),
        ));

        $this->app->bind(SlowExternalCallAnalyzer::class, static fn (Application $app): SlowExternalCallAnalyzer => new SlowExternalCallAnalyzer(
            $fp($app),
            self::float($app, 'tracing.thresholds.slow_http.warning_ms', 1000),
            self::float($app, 'tracing.thresholds.slow_http.critical_ms', 5000),
        ));

        $this->app->bind(CacheMissRateAnalyzer::class, static fn (Application $app): CacheMissRateAnalyzer => new CacheMissRateAnalyzer(
            $fp($app),
            self::int($app, 'tracing.thresholds.cache_miss_rate.min_lookups', 10),
            self::float($app, 'tracing.thresholds.cache_miss_rate.info_ratio', 0.5),
            self::float($app, 'tracing.thresholds.cache_miss_rate.warning_ratio', 0.8),
        ));

        $this->app->bind(ExceptionAnalyzer::class, static fn (Application $app): ExceptionAnalyzer => new ExceptionAnalyzer(
            $fp($app),
            $app->make(SourceLocator::class),
            self::array($app, 'tracing.thresholds.log_levels') ?: ExceptionAnalyzer::DEFAULT_LOG_LEVELS,
        ));

        $this->app->bind(MissingIndexAnalyzer::class, static fn (Application $app): MissingIndexAnalyzer => new MissingIndexAnalyzer($fp($app)));
    }

    private function registerExplain(): void
    {
        $timeout = static fn (Application $app): int => self::int($app, 'tracing.explain.timeout_ms', 1000);

        $this->app->singleton(MySqlExplainer::class, static fn (Application $app): MySqlExplainer => new MySqlExplainer($app->make(ConnectionResolverInterface::class), $timeout($app)));
        $this->app->singleton(PostgresExplainer::class, static fn (Application $app): PostgresExplainer => new PostgresExplainer($app->make(ConnectionResolverInterface::class), $timeout($app)));
        $this->app->singleton(SqliteExplainer::class, static fn (Application $app): SqliteExplainer => new SqliteExplainer($app->make(ConnectionResolverInterface::class), $timeout($app)));
        $this->app->tag([MySqlExplainer::class, PostgresExplainer::class, SqliteExplainer::class], self::EXPLAINER_TAG);

        $this->app->singleton(ExplainService::class, static function (Application $app): ExplainService {
            $minMs = $app->make(Config::class)->get('tracing.explain.min_query_ms');

            /** @var iterable<Explainer> $explainers */
            $explainers = $app->tagged(self::EXPLAINER_TAG);

            return new ExplainService(
                $explainers,
                $app->make(PlanInterpreter::class),
                self::cacheStore($app),
                self::explainEnabled($app),
                is_numeric($minMs) ? (float) $minMs : self::float($app, 'tracing.thresholds.slow_query.warning_ms', 100),
                self::int($app, 'tracing.explain.cache_minutes', 1440) * 60,
                self::int($app, 'tracing.explain.max_per_trace', 3),
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

        foreach (self::array($app, 'tracing.analyzers') as $key => $value) {
            [$class, $enabled] = is_string($key) ? [$key, filter_var($value, FILTER_VALIDATE_BOOL)] : [$value, true];

            if (! $enabled || ! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Analyzer::class)) {
                continue;
            }

            try {
                $analyzer = $app->make($class);
            } catch (Throwable $e) {
                self::logger($app)?->warning('[tracing] analyzer '.$class.' could not be resolved: '.$e->getMessage());

                continue;
            }

            if ($analyzer instanceof Analyzer) {
                $analyzers[] = $analyzer;
            }
        }

        return $analyzers;
    }

    private static function explainEnabled(Application $app): bool
    {
        $config = $app->make(Config::class);
        if (! filter_var($config->get('tracing.explain.enabled', true), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        $environments = $config->get('tracing.explain.environments');
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
            $store = $app->make(Config::class)->get('tracing.explain.cache_store');

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
