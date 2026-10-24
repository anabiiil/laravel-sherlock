<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Console;

use Anabil\Sherlock\Contracts\WatchWindow;
use Anabil\Sherlock\Enums\SherlockMode;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

/**
 * Temporary full tracing stored in cache; expires on its own. --stop ends it early.
 */
final class WatchCommand extends Command
{
    /** @var string */
    protected $signature = 'sherlock:watch
        {--minutes=30 : How long to trace fully}
        {--route= : Only requests whose path (or route name) matches this Str::is() pattern, e.g. "api/orders*"}
        {--user= : Only requests of this authenticated user id}
        {--stop : End the active watch window now}';

    /** @var string */
    protected $description = 'Fully trace requests, jobs and commands for a few minutes (all servers sharing the cache)';

    public function handle(WatchWindow $window, Repository $config): int
    {
        $mode = SherlockMode::fromConfig($config->get('sherlock.mode'));

        if ($mode->isOff()) {
            $this->components->warn('Sherlock is off (SHERLOCK_MODE=off): a watch window has no effect.');

            return self::FAILURE;
        }

        if (! $config->get('sherlock.watch.enabled', true) && ! $this->option('stop')) {
            $this->components->warn('The watch window is disabled (SHERLOCK_WATCH_ENABLED=false): production units ignore it.');
        }

        if ($this->option('stop')) {
            $window->stop();
            $this->components->info('Watch window stopped.');

            return self::SUCCESS;
        }

        $minutes = $this->option('minutes');
        $minutes = is_numeric($minutes) ? (int) $minutes : 0;
        if ($minutes < 1) {
            $this->components->error('--minutes must be a positive integer.');

            return self::FAILURE;
        }

        $max = $config->get('sherlock.watch.max_minutes', 1440);
        $max = is_numeric($max) ? (int) $max : 1440;
        if ($minutes > $max) {
            $this->components->warn("--minutes capped to {$max} (sherlock.watch.max_minutes).");
        }

        $route = $this->option('route');
        $user = $this->option('user');

        $state = $window->start(
            $minutes,
            is_string($route) && $route !== '' ? $route : null,
            is_string($user) && $user !== '' ? (ctype_digit($user) ? (int) $user : $user) : null,
        );

        $this->components->info(sprintf(
            'Full tracing on until %s (%d min)%s%s.',
            $state->expiresAt->format('Y-m-d H:i:s T'),
            (int) ceil($state->remainingSeconds() / 60),
            $state->routePattern !== null ? ', route '.$state->routePattern : '',
            $state->userId !== null ? ', user '.$state->userId : '',
        ));

        if ($mode->recordsEverything()) {
            $this->components->warn('Mode is "local": every unit is already fully traced.');
        }

        return self::SUCCESS;
    }
}
