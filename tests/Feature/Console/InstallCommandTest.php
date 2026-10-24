<?php

declare(strict_types=1);

use Anabil\Tracing\TracingServiceProvider;
use Illuminate\Support\Facades\File;

/*
| Publishing targets a per-test temporary directory, never the shared Testbench skeleton: a
| published migration there would leak into every other (possibly concurrent) test run.
| publishes() keys by source path, so booting the provider again redirects the targets.
*/
beforeEach(function (): void {
    $this->installPath = sys_get_temp_dir().'/tracing-install-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->installPath.'/config');
    File::ensureDirectoryExists($this->installPath.'/database/migrations');

    $this->app->useConfigPath($this->installPath.'/config');
    $this->app->useDatabasePath($this->installPath.'/database');
    $this->app->getProvider(TracingServiceProvider::class)->boot();
});

afterEach(function (): void {
    File::deleteDirectory($this->installPath);
});

it('publishes the config and migrations and prints the next steps', function (): void {
    $this->artisan('tracing:install')
        ->expectsConfirmation('Run the database migrations now?', 'no')
        ->expectsOutputToContain('PUBLISHED')
        ->expectsOutputToContain('Next steps')
        ->expectsOutputToContain('TRACING_MODE=production')
        ->expectsOutputToContain("Gate::define('viewTracing'")
        ->expectsOutputToContain('Use a real queue in production')
        ->assertSuccessful();

    expect(File::exists(config_path('tracing.php')))->toBeTrue()
        ->and(File::get(config_path('tracing.php')))->toBe(File::get(__DIR__.'/../../../config/tracing.php'))
        ->and(File::glob(database_path('migrations/*_create_tracing_tables.php')))->toHaveCount(1);
});

it('never publishes the migration twice and keeps an edited config unless forced', function (): void {
    $this->artisan('tracing:install')->expectsConfirmation('Run the database migrations now?', 'no')->assertSuccessful();
    File::put(config_path('tracing.php'), '<?php return ["edited" => true];');

    $this->artisan('tracing:install')
        ->expectsConfirmation('Run the database migrations now?', 'no')
        ->expectsOutputToContain('ALREADY PUBLISHED')
        ->expectsOutputToContain('KEPT')
        ->assertSuccessful();

    expect(File::glob(database_path('migrations/*_create_tracing_tables.php')))->toHaveCount(1)
        ->and(File::get(config_path('tracing.php')))->toContain('edited');

    $this->artisan('tracing:install', ['--force' => true])
        ->expectsConfirmation('Run the database migrations now?', 'no')
        ->assertSuccessful();

    expect(File::get(config_path('tracing.php')))->not->toContain('edited')
        ->and(File::glob(database_path('migrations/*_create_tracing_tables.php')))->toHaveCount(1);
});

it('does not migrate without confirmation in non-interactive mode', function (): void {
    $this->artisan('tracing:install', ['--no-interaction' => true])
        ->doesntExpectOutputToContain('Running migrations')
        ->assertSuccessful();

    expect(File::exists(config_path('tracing.php')))->toBeTrue();
});
