<?php

declare(strict_types=1);

namespace Anabil\Tracing\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;

/**
 * Publishes the config and migrations, optionally runs the migrations (after confirmation),
 * and prints the next steps. Re-running it is safe: an already-published migration is
 * never published a second time (vendor:publish would give it a new date).
 */
final class InstallCommand extends Command
{
    private const MIGRATION_SUFFIX = '_create_tracing_tables.php';

    private const NEXT_STEPS = [
        <<<'TEXT'
          1. Choose a recording mode in .env:

               TRACING_MODE=local         # dev machines: record everything
               TRACING_MODE=production    # thresholds + sampling (default)
               TRACING_SAMPLE_RATE=0.05   # baseline sample of healthy traces in production
               TRACING_MODE=off           # nothing is registered at all
        TEXT,
        <<<'TEXT'
          2. Run the migrations if you have not yet (php artisan migrate). To keep tracing data
             away from your main database, set TRACING_DB_CONNECTION to a dedicated connection
             before migrating.
        TEXT,
        <<<'TEXT'
          3. Use a real queue in production (redis / database / sqs) and run a worker; persistence
             (TRACING_PERSISTENCE=queue), notifications, AI analysis and the digest are queued.
             Optional: TRACING_QUEUE=tracing to keep them on their own queue.
        TEXT,
        <<<'TEXT'
          4. Make sure the scheduler runs (php artisan schedule:work, or a cron entry for
             schedule:run): tracing:prune and the digest are scheduled automatically.
        TEXT,
        <<<'TEXT'
          5. Open the dashboard at /tracing (TRACING_PATH). Outside the local environment, grant
             access in App\Providers\AppServiceProvider::boot():

               use Illuminate\Support\Facades\Gate;

               Gate::define('viewTracing', function ($user = null) {
                   return in_array($user?->email, ['you@example.com'], true);
               });
        TEXT,
        <<<'TEXT'
          6. Optional: notifications (TRACING_NOTIFICATIONS=true), AI analysis (TRACING_AI_ENABLED=true)
             and the digest (TRACING_DIGEST=true). Check everything with: php artisan tracing:status
        TEXT,
    ];

    /** @var string */
    protected $signature = 'tracing:install
        {--force : Overwrite an already published config file}
        {--migrate : Run the migrations without asking}';

    /** @var string */
    protected $description = 'Install Laravel Tracing: publish the config and migrations, then print the next steps';

    public function handle(Application $app, Filesystem $files): int
    {
        $this->components->info('Installing Laravel Tracing.');

        $configExists = $files->exists($app->configPath('tracing.php'));
        $this->callSilently('vendor:publish', ['--tag' => 'tracing-config', '--force' => (bool) $this->option('force')]);
        $this->components->twoColumnDetail(
            'config/tracing.php',
            $configExists && ! $this->option('force') ? '<fg=yellow;options=bold>KEPT (use --force to overwrite)</>' : '<fg=green;options=bold>PUBLISHED</>',
        );

        $migrationsPath = $app->databasePath('migrations');
        if ($this->publishedMigration($files, $migrationsPath) !== null) {
            $this->components->twoColumnDetail('database/migrations/*'.self::MIGRATION_SUFFIX, '<fg=yellow;options=bold>ALREADY PUBLISHED</>');
        } else {
            $this->callSilently('vendor:publish', ['--tag' => 'tracing-migrations']);
            $published = $this->publishedMigration($files, $migrationsPath);
            $this->components->twoColumnDetail(
                'database/migrations/'.($published === null ? '*'.self::MIGRATION_SUFFIX : basename($published)),
                $published === null ? '<fg=red;options=bold>FAILED</>' : '<fg=green;options=bold>PUBLISHED</>',
            );
        }

        if ($this->option('migrate') || ($this->input->isInteractive() && $this->confirm('Run the database migrations now?', false))) {
            $this->call('migrate');
        }

        $this->nextSteps();

        return self::SUCCESS;
    }

    /** @phpstan-impure */
    private function publishedMigration(Filesystem $files, string $path): ?string
    {
        if (! $files->isDirectory($path)) {
            return null;
        }

        $matches = $files->glob(rtrim($path, '/').'/*'.self::MIGRATION_SUFFIX);

        return $matches === [] ? null : $matches[0];
    }

    private function nextSteps(): void
    {
        $this->newLine();
        $this->components->info('Next steps');

        foreach (self::NEXT_STEPS as $step) {
            $this->line($step);
            $this->newLine();
        }
    }
}
