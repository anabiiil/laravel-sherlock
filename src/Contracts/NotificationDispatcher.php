<?php

declare(strict_types=1);

namespace Anabil\Tracing\Contracts;

use Anabil\Tracing\Data\NotificationMessage;

interface NotificationDispatcher
{
    /** Build and send (throttled per fingerprint) the notification for an issue. */
    public function notifyIssue(int|string $issueId): void;

    /**
     * Redact again and send to the given channels (null = all enabled), respecting per-channel
     * min_severity. One failing channel never blocks the others.
     *
     * @param  list<string>|null  $channels
     */
    public function send(NotificationMessage $message, ?array $channels = null): void;

    /**
     * @return array<string, NotificationChannel> enabled channels by name
     */
    public function channels(): array;
}
