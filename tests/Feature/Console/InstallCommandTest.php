<?php

declare(strict_types=1);

use Anabil\Sherlock\SherlockServiceProvider;
use Illuminate\Support\Facades\File;

/*
| Publishing targets a per-test temporary directory, never the shared Testbench skeleton: a
| published migration there would leak into every other (possibly concurrent) test run.
| publishes() keys by source path, so booting the provider again redirects the targets.
*/
beforeEach(function (): void {
    $this->installPath = sys_get_temp_dir().'/sherlock-install-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->installPath.'/config');
    File::ensureDirectoryExists($this->installPath.'/database/migrations');

    $this->app->useConfigPath($this->installPath.'/config');
    $this->app->useDatabasePath($this->installPath.'/database');
    $this->app->getProvider(SherlockServiceProvider::class)->boot();
});

afterEach(function (): void {
    File::deleteDirectory($this->installPath);
});

it('publishes the config and migrations and prints the next steps', function (): void {
    $this->artisan('sherlock:install')
        ->expectsConfirmation('Run the database migrations now?', 'no')
        ->expectsOutputToContain('PUBLISHED')
        ->expectsOutputToContain('Next steps')
        ->expectsOutputToContain('SHERLOCK_MODE=production')
        ->expectsOutputToContain("Gate::define('viewSherlock'")
        ->expectsOutputToContain('Use a real queue in production')
        ->assertSuccessful();

    expect(File::exists(config_path('sherlock.php')))->toBeTrue()
        ->and(File::get(config_path('sherlock.php')))->toBe(File::get(__DIR__.'/../../../config/sherlock.php'))
        ->and(File::glob(database_path('migrations/*_create_sherlock_tables.php')))->toHaveCount(1);
});

it('never publishes the migration twice and keeps an edited config unless forced', function (): void {
    $this->artisan('sherlock:install')->expectsConfirmation('Run the database migrations now?', 'no')->assertSuccessful();
    File::put(config_path('sherlock.php'), '<?php return ["edited" => true];');

    $this->artisan('sherlock:install')
        ->expectsConfirmation('Run the database migrations now?', 'no')
        ->expectsOutputToContain('ALREADY PUBLISHED')
        ->expectsOutputToContain('KEPT')
        ->assertSuccessful();

    expect(File::glob(database_path('migrations/*_create_sherlock_tables.php')))->toHaveCount(1)
        ->and(File::get(config_path('sherlock.php')))->toContain('edited');

    $this->artisan('sherlock:install', ['--force' => true])
        ->expectsConfirmation('Run the database migrations now?', 'no')
        ->assertSuccessful();

    expect(File::get(config_path('sherlock.php')))->not->toContain('edited')
        ->and(File::glob(database_path('migrations/*_create_sherlock_tables.php')))->toHaveCount(1);
});

it('does not migrate without confirmation in non-interactive mode', function (): void {
    $this->artisan('sherlock:install', ['--no-interaction' => true])
        ->doesntExpectOutputToContain('Running migrations')
        ->assertSuccessful();

    expect(File::exists(config_path('sherlock.php')))->toBeTrue();
});
