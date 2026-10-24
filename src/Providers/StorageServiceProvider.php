<?php

declare(strict_types=1);

namespace Anabil\Tracing\Providers;

use Anabil\Tracing\Ai\AiEligibility;
use Anabil\Tracing\Console\ClearCommand;
use Anabil\Tracing\Console\PruneCommand;
use Anabil\Tracing\Contracts\Fingerprinter;
use Anabil\Tracing\Contracts\IssueRepository;
use Anabil\Tracing\Contracts\IssueService;
use Anabil\Tracing\Contracts\PersistenceStrategy;
use Anabil\Tracing\Contracts\QueryNormalizer;
use Anabil\Tracing\Contracts\TraceRepository;
use Anabil\Tracing\Issues\DefaultIssueService;
use Anabil\Tracing\Issues\IssueContextBuilder;
use Anabil\Tracing\Storage\Persistence\AfterResponsePersistence;
use Anabil\Tracing\Storage\Persistence\QueuedPersistence;
use Anabil\Tracing\Storage\Persistence\TraceWriter;
use Anabil\Tracing\Storage\Repositories\DatabaseIssueRepository;
use Anabil\Tracing\Storage\Repositories\DatabaseTraceRepository;
use Anabil\Tracing\Storage\Support\QuietReporter;
use Anabil\Tracing\Storage\Support\RecordingPauser;
use Anabil\Tracing\Storage\Tables;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Owns storage: repositories, persistence strategies (after_response / queue), IssueService (query builder on the tracing connection), prune / clear commands and retention scheduling.
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
                self::int($config->get('tracing.storage.max_queries_per_trace'), 200),
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
            $ai = (bool) $config->get('tracing.ai.enabled');
            $notify = (bool) $config->get('tracing.notifications.enabled');

            return new DefaultIssueService(
                $app->make(IssueRepository::class),
                $app->make(Dispatcher::class),
                new IssueContextBuilder,
                $app->make(Bus::class),
                self::int($config->get('tracing.storage.issue_traces_cap'), 10),
                $ai || $notify,
                // A sync queue runs the chain right here: inside an HTTP request that is the
                // terminating callback of a PHP worker, which an AI call (up to ai.timeout) would
                // hold. Skipped there (tracing:analyze / Re-analyze still work); queue workers
                // and commands keep it.
                ! (self::servesHttp($app) && self::queueIsSync($config)),
                $notify,
                new AiEligibility($config),
            );
        });

        $this->app->singleton(TraceWriter::class);

        $this->app->singleton(PersistenceStrategy::class, static function (Application $app): PersistenceStrategy {
            $mode = $app->make(Repository::class)->get('tracing.storage.persistence', 'after_response');

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

            if (! $config->get('tracing.retention.schedule', true)) {
                return;
            }

            $at = $config->get('tracing.retention.schedule_at', '03:00');

            $schedule->command(PruneCommand::class)->dailyAt(is_string($at) && $at !== '' ? $at : '03:00');
        });
    }

    /** PHP-FPM / mod_php / Octane: a process serving HTTP requests (not a queue worker or command). */
    private static function servesHttp(Application $app): bool
    {
        return ! $app->runningInConsole() || isset($_SERVER['LARAVEL_OCTANE']);
    }

    /** Whether package jobs run inline (the tracing queue connection uses the sync driver). */
    private static function queueIsSync(Repository $config): bool
    {
        $connection = $config->get('tracing.queue.connection');
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
