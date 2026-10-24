<?php

declare(strict_types=1);

namespace Anabil\Tracing\Support\Null;

use Anabil\Tracing\Contracts\NotificationDispatcher;
use Anabil\Tracing\Data\NotificationMessage;

final class NullNotificationDispatcher implements NotificationDispatcher
{
    public function notifyIssue(int|string $issueId): void {}

    public function send(NotificationMessage $message, ?array $channels = null): void {}

    public function channels(): array
    {
        return [];
    }
}
