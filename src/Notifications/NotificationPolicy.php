<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Notifications;

use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;
use Illuminate\Contracts\Config\Repository;

/**
 * Reads config("sherlock.notifications") on every call (so runtime config changes apply):
 * global switch, enabled channel names, severity thresholds, notify_on toggles, throttle window.
 */
final class NotificationPolicy
{
    public const KIND_NEW = 'new';

    public const KIND_REGRESSED = 'regressed';

    public const KIND_EXCEPTIONS = 'exceptions';

    public function __construct(
        private readonly Repository $config,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('sherlock.notifications.enabled', false);
    }

    /**
     * @return list<string> channel names listed in config, lower-cased, unique
     */
    public function channelNames(): array
    {
        $channels = $this->config->get('sherlock.notifications.channels', []);

        if (is_string($channels)) {
            $channels = explode(',', $channels);
        }

        if (! is_array($channels)) {
            return [];
        }

        $names = [];
        foreach ($channels as $channel) {
            if (is_string($channel) && trim($channel) !== '') {
                $names[] = strtolower(trim($channel));
            }
        }

        return array_values(array_unique($names));
    }

    public function minSeverity(): Severity
    {
        return Severity::fromConfig($this->config->get('sherlock.notifications.min_severity'));
    }

    /** A channel may raise the global threshold, never lower it. */
    public function channelMinSeverity(string $channel): Severity
    {
        $global = $this->minSeverity();
        $own = Severity::fromConfig($this->config->get("sherlock.notifications.{$channel}.min_severity"), $global);

        return Severity::max($global, $own);
    }

    public function channelAccepts(string $channel, Severity $severity): bool
    {
        return $severity->isAtLeast($this->channelMinSeverity($channel));
    }

    /** notify_on toggle; missing keys default to true. */
    public function notifyOn(string $kind): bool
    {
        return (bool) $this->config->get("sherlock.notifications.notify_on.{$kind}", true);
    }

    /**
     * Whether an issue of this type should be notified at all (first send and throttled repeats).
     * Errors follow the "exceptions" toggle; performance issues follow "new" / "regressed".
     */
    public function allowsType(IssueType $type, bool $regressed = false): bool
    {
        if ($type->isError()) {
            return $this->notifyOn(self::KIND_EXCEPTIONS);
        }

        return $this->notifyOn($regressed ? self::KIND_REGRESSED : self::KIND_NEW);
    }

    public function throttleMinutes(): int
    {
        $minutes = $this->config->get('sherlock.notifications.throttle_minutes', 60);

        return max(1, is_numeric($minutes) ? (int) $minutes : 60);
    }

    public function timeout(): int
    {
        $timeout = $this->config->get('sherlock.notifications.timeout', 10);

        return max(1, min(30, is_numeric($timeout) ? (int) $timeout : 10));
    }

    public function dashboardBaseUrl(): ?string
    {
        $url = $this->config->get('sherlock.notifications.dashboard_url');

        return is_string($url) && trim($url) !== '' ? rtrim(trim($url), '/') : null;
    }

    public function dashboardEnabled(): bool
    {
        return (bool) $this->config->get('sherlock.dashboard.enabled', true);
    }

    /**
     * @return array<string, mixed>
     */
    public function channelConfig(string $channel): array
    {
        $config = $this->config->get("sherlock.notifications.{$channel}", []);

        return is_array($config) ? $config : [];
    }

    /** Whether the package queue runs jobs synchronously (delayed jobs are then impossible). */
    public function queueIsSync(): bool
    {
        $connection = $this->config->get('sherlock.queue.connection');
        if (! is_string($connection) || $connection === '') {
            $connection = $this->config->get('queue.default', 'sync');
        }

        if (! is_string($connection)) {
            return true;
        }

        $driver = $this->config->get("queue.connections.{$connection}.driver", $connection);

        return $driver === 'sync';
    }
}
