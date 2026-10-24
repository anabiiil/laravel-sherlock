<?php

declare(strict_types=1);

namespace Anabil\Tracing\Console;

use Anabil\Tracing\Contracts\AiBudget;
use Anabil\Tracing\Contracts\WatchWindow;
use Anabil\Tracing\Enums\TracingMode;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * Current configuration and state: mode, sampling, watch window, notification channels,
 * AI driver and today's AI budget. Reads config only (no secrets are printed).
 */
final class StatusCommand extends Command
{
    /** @var string */
    protected $signature = 'tracing:status';

    /** @var string */
    protected $description = 'Show the tracing mode, watch window, notification channels and AI budget';

    public function handle(Repository $config, WatchWindow $window, AiBudget $budget): int
    {
        $mode = TracingMode::fromConfig($config->get('tracing.mode'));
        $rate = $config->get('tracing.sample_rate', 0.0);

        $this->components->twoColumnDetail('<fg=green;options=bold>Capture</>');
        $this->components->twoColumnDetail('Mode', $mode->value);
        $this->components->twoColumnDetail('Sample rate', is_numeric($rate) ? rtrim(rtrim(number_format((float) $rate * 100, 2), '0'), '.').'%' : '-');
        $this->components->twoColumnDetail('Header trigger', $this->headerState($config));
        $this->components->twoColumnDetail('Watch window', $mode->isOff() ? 'n/a (off)' : $this->watchState($window));

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>Notifications</>');
        $enabled = (bool) $config->get('tracing.notifications.enabled');
        $channels = $config->get('tracing.notifications.channels', []);
        $channels = is_array($channels) ? array_values(array_filter($channels, 'is_string')) : [];
        $this->components->twoColumnDetail('Enabled', $enabled ? 'yes' : 'no');
        $this->components->twoColumnDetail('Channels', $channels === [] ? 'none' : implode(', ', $channels));
        foreach ($channels as $channel) {
            $this->components->twoColumnDetail("  {$channel}", $this->channelConfigured($config, $channel) ? 'configured' : '<fg=yellow>missing settings</>');
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>AI analysis</>');
        $aiEnabled = (bool) $config->get('tracing.ai.enabled');
        $driver = $config->get('tracing.ai.driver');
        $this->components->twoColumnDetail('Enabled', $aiEnabled ? 'yes' : 'no');
        $this->components->twoColumnDetail('Driver', is_string($driver) ? $driver : '-');
        $this->components->twoColumnDetail('Budget today', $this->budgetState($budget));

        return self::SUCCESS;
    }

    private function headerState(Repository $config): string
    {
        $secret = $config->get('tracing.header.secret');
        $name = $config->get('tracing.header.name', 'X-Tracing');

        return $config->get('tracing.header.enabled', true) && is_string($secret) && $secret !== ''
            ? 'enabled ('.(is_string($name) ? $name : 'X-Tracing').')'
            : 'disabled';
    }

    private function watchState(WatchWindow $window): string
    {
        try {
            $state = $window->current();
        } catch (Throwable $e) {
            return '<fg=yellow>unavailable: '.$e->getMessage().'</>';
        }

        if ($state === null) {
            return 'inactive';
        }

        $filters = array_filter([
            $state->routePattern !== null ? 'route '.$state->routePattern : null,
            $state->userId !== null ? 'user '.$state->userId : null,
        ]);

        return sprintf(
            '<fg=green>active</> until %s (%d min left)%s',
            $state->expiresAt->format('H:i:s T'),
            (int) ceil($state->remainingSeconds() / 60),
            $filters === [] ? '' : ', '.implode(', ', $filters),
        );
    }

    private function channelConfigured(Repository $config, string $channel): bool
    {
        $filled = static fn (mixed $value): bool => (is_string($value) && $value !== '') || (is_array($value) && $value !== []);

        return match ($channel) {
            'slack' => $filled($config->get('tracing.notifications.slack.webhook_url')),
            'telegram' => $filled($config->get('tracing.notifications.telegram.bot_token'))
                && $filled($config->get('tracing.notifications.telegram.chat_id')),
            'mail' => $filled($config->get('tracing.notifications.mail.to')),
            default => true,
        };
    }

    private function budgetState(AiBudget $budget): string
    {
        try {
            return sprintf('%d / %d used (%d remaining)', $budget->used(), $budget->limit(), $budget->remaining());
        } catch (Throwable $e) {
            return '<fg=yellow>unavailable: '.$e->getMessage().'</>';
        }
    }
}
