<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;

/**
 * Publishes the config and migrations, optionally runs the migrations (after confirmation),
 * and prints the next steps. Re-running it is safe and upgrades: only migrations the app does
 * not have yet are published.
 */
final class InstallCommand extends Command
{
    private const MIGRATIONS_PATH = __DIR__.'/../../database/migrations';

    private const NEXT_STEPS = [
        <<<'TEXT'
          1. Choose a recording mode in .env:

               SHERLOCK_MODE=local         # dev machines: record everything
               SHERLOCK_MODE=production    # thresholds + sampling (default)
               SHERLOCK_SAMPLE_RATE=0.05   # baseline sample of healthy traces in production
               SHERLOCK_MODE=off           # nothing is registered at all
        TEXT,
        <<<'TEXT'
          2. Run the migrations if you have not yet (php artisan migrate). To keep trace data
             away from your main database, set SHERLOCK_DB_CONNECTION to a dedicated connection
             before migrating.
        TEXT,
        <<<'TEXT'
          3. Use a real queue in production (redis / database / sqs) and run a worker; persistence
             (SHERLOCK_PERSISTENCE=queue), notifications, AI analysis and the digest are queued.
             Optional: SHERLOCK_QUEUE=sherlock to keep them on their own queue.
        TEXT,
        <<<'TEXT'
          4. Make sure the scheduler runs (php artisan schedule:work, or a cron entry for
             schedule:run): sherlock:prune and the digest are scheduled automatically.
        TEXT,
        <<<'TEXT'
          5. Open the dashboard at /sherlock (SHERLOCK_PATH). Outside the local environment, grant
             access in App\Providers\AppServiceProvider::boot():

               use Illuminate\Support\Facades\Gate;

               Gate::define('viewSherlock', function ($user = null) {
                   return in_array($user?->email, ['you@example.com'], true);
               });
        TEXT,
        <<<'TEXT'
          6. Optional: notifications (SHERLOCK_NOTIFICATIONS=true), AI analysis (SHERLOCK_AI_ENABLED=true)
             and the digest (SHERLOCK_DIGEST=true). Check everything with: php artisan sherlock:status
        TEXT,
    ];

    /** @var string */
    protected $signature = 'sherlock:install
        {--force : Overwrite an already published config file}
        {--migrate : Run the migrations without asking}';

    /** @var string */
    protected $description = 'Install Laravel Sherlock: publish the config and migrations, then print the next steps';

    public function handle(Application $app, Filesystem $files): int
    {
        $this->components->info('Installing Laravel Sherlock.');

        $configExists = $files->exists($app->configPath('sherlock.php'));
        $this->callSilently('vendor:publish', ['--tag' => 'sherlock-config', '--force' => (bool) $this->option('force')]);
        $this->components->twoColumnDetail(
            'config/sherlock.php',
            $configExists && ! $this->option('force') ? '<fg=yellow;options=bold>KEPT (use --force to overwrite)</>' : '<fg=green;options=bold>PUBLISHED</>',
        );

        $this->publishMigrations($files, $app->databasePath('migrations'));

        if ($this->option('migrate') || ($this->input->isInteractive() && $this->confirm('Run the database migrations now?', false))) {
            $this->call('migrate');
        }

        $this->nextSteps();

        return self::SUCCESS;
    }

    /**
     * Publishes each package migration that the app does not have yet, matched by name without
     * its date prefix. Upgrades therefore receive new migrations, while already-published ones
     * are never copied twice (vendor:publish would give them a new date).
     */
    private function publishMigrations(Filesystem $files, string $target): void
    {
        $files->ensureDirectoryExists($target);

        $sources = $files->glob(self::MIGRATIONS_PATH.'/*.php');
        sort($sources);
        $timestamp = time();

        foreach ($sources as $source) {
            $name = self::migrationName(basename($source));
            $existing = $files->glob(rtrim($target, '/').'/*_'.$name);

            if ($existing !== []) {
                $this->components->twoColumnDetail('database/migrations/'.basename($existing[0]), '<fg=yellow;options=bold>ALREADY PUBLISHED</>');

                continue;
            }

            $published = date('Y_m_d_His', $timestamp++).'_'.$name;
            $files->copy($source, rtrim($target, '/').'/'.$published);
            $this->components->twoColumnDetail('database/migrations/'.$published, '<fg=green;options=bold>PUBLISHED</>');
        }
    }

    /** Strips the "YYYY_MM_DD_HHMMSS_" prefix. */
    private static function migrationName(string $file): string
    {
        return (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $file);
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
