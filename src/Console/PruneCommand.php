<?php

declare(strict_types=1);

namespace Anabil\Tracing\Console;

use Anabil\Tracing\Contracts\IssueRepository;
use Anabil\Tracing\Contracts\TraceRepository;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

/**
 * Retention cleanup (config "tracing.retention"), in chunks. Scheduled daily by default.
 */
final class PruneCommand extends Command
{
    /** @var string */
    protected $signature = 'tracing:prune';

    /** @var string */
    protected $description = 'Delete traces and issues older than the configured retention';

    public function handle(TraceRepository $traces, IssueRepository $issues, Repository $config): int
    {
        $chunk = $config->get('tracing.retention.chunk_size', 1000);
        $chunk = is_numeric($chunk) ? max(1, (int) $chunk) : 1000;

        foreach (['traces' => $traces, 'issues' => $issues] as $kind => $repository) {
            $days = $config->get("tracing.retention.{$kind}");

            if (! is_numeric($days)) {
                $this->components->info(ucfirst($kind).': kept forever (retention is null).');

                continue;
            }

            $before = CarbonImmutable::now()->subDays(max(0, (int) $days));
            $deleted = $repository->pruneOlderThan($before, $chunk);

            $this->components->info(sprintf('%s: deleted %d older than %d day(s).', ucfirst($kind), $deleted, (int) $days));
        }

        return self::SUCCESS;
    }
}
