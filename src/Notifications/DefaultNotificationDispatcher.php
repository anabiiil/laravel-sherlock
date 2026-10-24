<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Notifications;

use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Contracts\NotificationChannel;
use Anabil\Sherlock\Contracts\NotificationDispatcher;
use Anabil\Sherlock\Contracts\Redactor;
use Anabil\Sherlock\Data\IssueRecord;
use Anabil\Sherlock\Data\NotificationMessage;
use Anabil\Sherlock\Digest\DigestBuilder;
use Anabil\Sherlock\Enums\IssueStatus;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Notifications\Channels\RedactingChannel;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Filters channels (global switch, config list, configured, per-channel min_severity), redacts
 * every outgoing message (RedactingChannel decorator) and isolates failures per channel:
 * one failing channel never blocks the others and nothing is ever thrown to the caller.
 */
final class DefaultNotificationDispatcher implements NotificationDispatcher
{
    private const FAILURE_LOG_SECONDS = 600;

    /** @var array<string, NotificationChannel> */
    private array $channels = [];

    /**
     * @param  iterable<NotificationChannel>  $channels  all known channels (enabled or not)
     */
    public function __construct(
        private readonly NotificationPolicy $policy,
        private readonly IssueRepository $issues,
        private readonly IssueMessageFactory $messages,
        private readonly Redactor $redactor,
        private readonly Cache $cache,
        iterable $channels,
        private readonly ?LoggerInterface $logger = null,
    ) {
        foreach ($channels as $channel) {
            $this->channels[strtolower($channel->name())] = $channel instanceof RedactingChannel
                ? $channel
                : new RedactingChannel($channel, $this->redactor);
        }
    }

    public function notifyIssue(int|string $issueId): void
    {
        if (! $this->policy->enabled()) {
            return;
        }

        try {
            $issue = $this->issues->find($issueId);
        } catch (Throwable $e) {
            $this->logFailure('repository', $e);

            return;
        }

        if ($issue === null || $issue->status !== IssueStatus::Open) {
            return; // ignored or resolved in the meantime
        }

        if (! $this->policy->allowsType($issue->type, $issue->regressedAt !== null) || ! $this->accepts($issue->severity)) {
            return;
        }

        $event = $issue->regressedAt ?? $issue->firstSeenAt;

        // Already notified for this event (duplicate job, or a summary went out first).
        if ($issue->lastNotifiedAt !== null && $issue->lastNotifiedAt >= $event) {
            return;
        }

        if (! $this->claim('sherlock:notifications:issue:'.$issue->id.':'.$event->getTimestamp())) {
            return;
        }

        $results = $this->deliver($this->messages->forIssue($issue));

        if ($results !== []) {
            $this->markNotified($issue);
        }
    }

    public function send(NotificationMessage $message, ?array $channels = null): void
    {
        if (! $this->policy->enabled()) {
            return;
        }

        $this->deliver($message, $channels);
    }

    /**
     * Send to the eligible channels and report per channel (null = sent, Throwable = failed).
     * Never throws. $force (sherlock:test-notification) ignores the global switch, the
     * configured channel list and severity thresholds, but still requires credentials and
     * still redacts. Digests (DigestBuilder::FINGERPRINT) skip severity thresholds; explicit
     * $names are honoured even when not in notifications.channels, null / [] = enabled channels.
     *
     * @param  list<string>|null  $names
     * @return array<string, Throwable|null>
     */
    public function deliver(NotificationMessage $message, ?array $names = null, bool $force = false): array
    {
        $results = [];

        $eligible = self::isDigest($message)
            ? $this->digestChannels($names, $force)
            : $this->eligible($message->severity, $names, $force);

        foreach ($eligible as $name => $channel) {
            try {
                $channel->send($message);
                $results[$name] = null;
            } catch (Throwable $e) {
                $results[$name] = $e;
                $this->logFailure($name, $e);
            }
        }

        return $results;
    }

    public function channels(): array
    {
        $enabled = [];

        foreach ($this->policy->channelNames() as $name) {
            $channel = $this->channels[$name] ?? null;
            if ($channel !== null && $this->configured($channel)) {
                $enabled[$name] = $channel;
            }
        }

        return $enabled;
    }

    /**
     * Every registered channel (redacting decorators), enabled or not.
     *
     * @return array<string, NotificationChannel>
     */
    public function allChannels(): array
    {
        return $this->channels;
    }

    /** Whether at least one enabled channel would deliver a message of this severity. */
    public function accepts(Severity $severity): bool
    {
        return $this->policy->enabled() && $this->eligible($severity) !== [];
    }

    /**
     * @param  list<string>|null  $names
     * @return array<string, NotificationChannel>
     */
    private function eligible(Severity $severity, ?array $names = null, bool $force = false): array
    {
        $wanted = $names === null ? null : array_map(static fn (string $name): string => strtolower(trim($name)), $names);
        $candidates = $force ? array_filter($this->channels, $this->configured(...)) : $this->channels();

        $eligible = [];
        foreach ($candidates as $name => $channel) {
            if ($wanted !== null && ! in_array($name, $wanted, true)) {
                continue;
            }

            if (! $force && ! $this->policy->channelAccepts($name, $severity)) {
                continue;
            }

            $eligible[$name] = $channel;
        }

        return $eligible;
    }

    public static function isDigest(NotificationMessage $message): bool
    {
        return $message->fingerprint === DigestBuilder::FINGERPRINT;
    }

    /**
     * Digest targets: the explicitly requested channels (configured ones, listed in
     * notifications.channels or not), else every enabled channel. No severity thresholds.
     *
     * @param  list<string>|null  $names
     * @return array<string, NotificationChannel>
     */
    private function digestChannels(?array $names, bool $force): array
    {
        $wanted = array_values(array_filter(
            array_map(static fn (string $name): string => strtolower(trim($name)), $names ?? []),
            static fn (string $name): bool => $name !== '',
        ));

        if ($wanted === []) {
            return $force ? array_filter($this->channels, $this->configured(...)) : $this->channels();
        }

        $eligible = [];
        foreach (array_unique($wanted) as $name) {
            $channel = $this->channels[$name] ?? null;
            if ($channel !== null && $this->configured($channel)) {
                $eligible[$name] = $channel;
            }
        }

        return $eligible;
    }

    private function configured(NotificationChannel $channel): bool
    {
        try {
            return $channel->isConfigured();
        } catch (Throwable) {
            return false;
        }
    }

    private function markNotified(IssueRecord $issue): void
    {
        try {
            $this->issues->markNotified($issue->id, CarbonImmutable::now());
        } catch (Throwable $e) {
            $this->logFailure('repository', $e);
        }
    }

    /** Atomic "first one wins" guard (Cache::add); a broken cache store never blocks sending. */
    private function claim(string $key): bool
    {
        try {
            return $this->cache->add($key, 1, $this->policy->throttleMinutes() * 60);
        } catch (Throwable) {
            return true;
        }
    }

    /** Logged quietly, at most once per channel every 10 minutes (no log floods, no retries). */
    private function logFailure(string $channel, Throwable $e): void
    {
        if ($this->logger === null) {
            return;
        }

        try {
            if (! $this->cache->add('sherlock:notifications:failed:'.$channel, 1, self::FAILURE_LOG_SECONDS)) {
                return;
            }
        } catch (Throwable) {
            // Fall through and log.
        }

        $this->logger->warning('[sherlock] Notification channel failed: '.$this->redactor->redactString(NotificationFailed::scrub($e->getMessage(), [])), [
            'channel' => $channel,
            'exception' => $e::class,
        ]);
    }
}
