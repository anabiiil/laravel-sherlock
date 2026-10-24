<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Providers;

use Anabil\Sherlock\Ai\AiEligibility;
use Anabil\Sherlock\Console\ClearCommand;
use Anabil\Sherlock\Console\PruneCommand;
use Anabil\Sherlock\Contracts\Fingerprinter;
use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Contracts\IssueService;
use Anabil\Sherlock\Contracts\PersistenceStrategy;
use Anabil\Sherlock\Contracts\QueryNormalizer;
use Anabil\Sherlock\Contracts\TraceRepository;
use Anabil\Sherlock\Issues\DefaultIssueService;
use Anabil\Sherlock\Issues\IssueContextBuilder;
use Anabil\Sherlock\Releases\ReleaseComparator;
use Anabil\Sherlock\Storage\Persistence\AfterResponsePersistence;
use Anabil\Sherlock\Storage\Persistence\QueuedPersistence;
use Anabil\Sherlock\Storage\Persistence\TraceWriter;
use Anabil\Sherlock\Storage\Repositories\DatabaseIssueRepository;
use Anabil\Sherlock\Storage\Repositories\DatabaseTraceRepository;
use Anabil\Sherlock\Storage\Support\QuietReporter;
use Anabil\Sherlock\Storage\Support\RecordingPauser;
use Anabil\Sherlock\Storage\Tables;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Owns storage: repositories, persistence strategies (after_response / queue), IssueService (query builder on the Sherlock connection), prune / clear commands and retention scheduling.
 */
final class StorageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Tables::class, static fn (): Tables => Tables::fromConfig());
        $this->app->singleton(RecordingPauser::class, static fn (Application $app): RecordingPauser => new RecordingPauser($app));
        $this->app->singleton(QuietReporter::class, static fn (Application $app): QuietReporter => new QuietReporter(
            $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null,
        ));

        $this->app->singleton(TraceRepository::class, static function (Application $app): DatabaseTraceRepository {
            $config = $app->make(Repository::class);

            return new DatabaseTraceRepository(
                $app->make(ConnectionResolverInterface::class),
                $app->make(Tables::class),
                Tables::connection(),
                self::int($config->get('sherlock.storage.max_queries_per_trace'), 200),
                $app->make(QueryNormalizer::class),
                $app->make(Fingerprinter::class),
            );
        });

        $this->app->singleton(IssueRepository::class, static fn (Application $app): DatabaseIssueRepository => new DatabaseIssueRepository(
            $app->make(ConnectionResolverInterface::class),
            $app->make(Tables::class),
            Tables::connection(),
        ));

        $this->app->singleton(IssueService::class, static function (Application $app): DefaultIssueService {
            $config = $app->make(Repository::class);
            $ai = (bool) $config->get('sherlock.ai.enabled');
            $notify = (bool) $config->get('sherlock.notifications.enabled');

            return new DefaultIssueService(
                $app->make(IssueRepository::class),
                $app->make(Dispatcher::class),
                new IssueContextBuilder,
                $app->make(Bus::class),
                self::int($config->get('sherlock.storage.issue_traces_cap'), 10),
                $ai || $notify,
                // A sync queue runs the chain right here: inside an HTTP request that is the
                // terminating callback of a PHP worker, which an AI call (up to ai.timeout) would
                // hold. Skipped there (sherlock:analyze / Re-analyze still work); queue workers
                // and commands keep it.
                ! (self::servesHttp($app) && self::queueIsSync($config)),
                $notify,
                new AiEligibility($config),
            );
        });

        $this->app->singleton(ReleaseComparator::class, static function (Application $app): ReleaseComparator {
            $config = $app->make(Repository::class);
            $change = $config->get('sherlock.releases.compare_min_change');
            $delta = $config->get('sherlock.releases.compare_min_delta_ms');

            return new ReleaseComparator(
                $app->make(TraceRepository::class),
                $app->make(IssueRepository::class),
                self::int($config->get('sherlock.releases.compare_min_samples'), 20),
                is_numeric($change) ? max(0.0, (float) $change) : 0.1,
                is_numeric($delta) ? max(0.0, (float) $delta) : 5.0,
            );
        });

        $this->app->singleton(TraceWriter::class);

        $this->app->singleton(PersistenceStrategy::class, static function (Application $app): PersistenceStrategy {
            $mode = $app->make(Repository::class)->get('sherlock.storage.persistence', 'after_response');

            return $mode === 'queue'
                ? $app->make(QueuedPersistence::class)
                : $app->make(AfterResponsePersistence::class);
        });
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([PruneCommand::class, ClearCommand::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $config = $this->app->make(Repository::class);

            if (! $config->get('sherlock.retention.schedule', true)) {
                return;
            }

            $at = $config->get('sherlock.retention.schedule_at', '03:00');

            $schedule->command(PruneCommand::class)->dailyAt(is_string($at) && $at !== '' ? $at : '03:00');
        });
    }

    /** PHP-FPM / mod_php / Octane: a process serving HTTP requests (not a queue worker or command). */
    private static function servesHttp(Application $app): bool
    {
        return ! $app->runningInConsole() || isset($_SERVER['LARAVEL_OCTANE']);
    }

    /** Whether package jobs run inline (the Sherlock queue connection uses the sync driver). */
    private static function queueIsSync(Repository $config): bool
    {
        $connection = $config->get('sherlock.queue.connection');
        if (! is_string($connection) || $connection === '') {
            $connection = $config->get('queue.default', 'sync');
        }

        if (! is_string($connection)) {
            return true;
        }

        return $config->get("queue.connections.{$connection}.driver", $connection) === 'sync';
    }

    private static function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? max(1, (int) $value) : $default;
    }
}
