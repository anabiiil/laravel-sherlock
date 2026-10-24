<?php

declare(strict_types=1);

namespace Anabil\Tracing\Console;

use Anabil\Tracing\Contracts\DigestService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

/**
 * Sends the digest now, in this process. Without --force it respects "digest.enabled" and
 * "digest.every_days" (safe to run from cron); --force sends regardless of both.
 */
final class DigestCommand extends Command
{
    /** @var string */
    protected $signature = 'tracing:digest {--force : Send even when the digest is disabled or not due yet}';

    /** @var string */
    protected $description = 'Send the tracing digest (top issues, slowest entries, top queries) now';

    public function handle(DigestService $digest, Repository $config): int
    {
        $force = (bool) $this->option('force');

        if (! $force && ! $config->get('tracing.digest.enabled')) {
            $this->components->warn('The digest is disabled (TRACING_DIGEST=false). Use --force to send it anyway.');

            return self::SUCCESS;
        }

        if (! $force && ! $digest->isDue()) {
            $days = $config->get('tracing.digest.every_days', 1);
            $this->components->info(sprintf('The digest is not due yet (every %s day(s)). Use --force to send it now.', is_scalar($days) ? (string) $days : '1'));

            return self::SUCCESS;
        }

        if (! $digest->send($force)) {
            $this->components->warn('The digest was not sent (another process is sending it, or it is not due).');

            return self::SUCCESS;
        }

        $channels = $config->get('tracing.digest.channels', []);
        $channels = is_array($channels) ? array_values(array_filter($channels, 'is_string')) : [];

        $this->components->info('Digest sent to: '.($channels === [] ? 'all enabled notification channels' : implode(', ', $channels)).'.');

        return self::SUCCESS;
    }
}
