<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Support\Null;

use Anabil\Sherlock\Contracts\NotificationDispatcher;
use Anabil\Sherlock\Data\NotificationMessage;

final class NullNotificationDispatcher implements NotificationDispatcher
{
    public function notifyIssue(int|string $issueId): void {}

    public function send(NotificationMessage $message, ?array $channels = null): void {}

    public function channels(): array
    {
        return [];
    }
}
