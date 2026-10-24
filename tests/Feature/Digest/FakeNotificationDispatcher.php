<?php

declare(strict_types=1);

namespace Anabil\Tracing\Tests\Feature\Digest;

use Anabil\Tracing\Contracts\NotificationDispatcher;
use Anabil\Tracing\Data\NotificationMessage;
use RuntimeException;

/**
 * Records what the digest sends (the real dispatcher is tested on its own).
 */
final class FakeNotificationDispatcher implements NotificationDispatcher
{
    /** @var list<array{message: NotificationMessage, channels: list<string>|null}> */
    public array $sent = [];

    public function notifyIssue(int|string $issueId): void
    {
        throw new RuntimeException('The digest must not send issue notifications.');
    }

    public function send(NotificationMessage $message, ?array $channels = null): void
    {
        $this->sent[] = ['message' => $message, 'channels' => $channels];
    }

    public function channels(): array
    {
        return [];
    }

    public function last(): ?NotificationMessage
    {
        return $this->sent === [] ? null : $this->sent[array_key_last($this->sent)]['message'];
    }
}
