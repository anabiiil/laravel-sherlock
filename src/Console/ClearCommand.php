<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Console;

use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Contracts\TraceRepository;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

/**
 * Manual cleanup: everything, or only data older than N days; traces, issues or both.
 */
final class ClearCommand extends Command
{
    /** @var string */
    protected $signature = 'sherlock:clear
        {--older-than= : Only delete data older than this many days}
        {--only= : Only clear "traces" or "issues"}
        {--force : Skip the confirmation}';

    /** @var string */
    protected $description = 'Delete stored traces and/or issues';

    public function handle(TraceRepository $traces, IssueRepository $issues, Repository $config): int
    {
        $only = $this->option('only');
        $only = is_string($only) && $only !== '' ? strtolower($only) : null;

        if ($only !== null && ! in_array($only, ['traces', 'issues'], true)) {
            $this->components->error('--only must be "traces" or "issues".');

            return self::INVALID;
        }

        $olderThan = $this->option('older-than');

        if ($olderThan !== null && (! is_numeric($olderThan) || (int) $olderThan < 0)) {
            $this->components->error('--older-than must be a number of days.');

            return self::INVALID;
        }

        // No --older-than: everything, including rows stamped slightly in the future (clock skew).
        $before = $olderThan === null
            ? CarbonImmutable::now()->addYears(100)
            : CarbonImmutable::now()->subDays((int) $olderThan);

        $what = $only ?? 'traces and issues';
        $scope = $olderThan === null ? 'all stored '.$what : $what.' older than '.(int) $olderThan.' day(s)';

        if (! $this->option('force') && ! $this->confirm("Delete {$scope}?")) {
            $this->components->warn('Nothing deleted.');

            return self::SUCCESS;
        }

        $chunk = $config->get('sherlock.retention.chunk_size', 1000);
        $chunk = is_numeric($chunk) ? max(1, (int) $chunk) : 1000;

        if ($only === null || $only === 'traces') {
            $this->components->info(sprintf('Traces: deleted %d.', $traces->pruneOlderThan($before, $chunk)));
        }

        if ($only === null || $only === 'issues') {
            $this->components->info(sprintf('Issues: deleted %d.', $issues->pruneOlderThan($before, $chunk)));
        }

        return self::SUCCESS;
    }
}
