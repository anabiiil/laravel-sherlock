<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Contracts;

use Anabil\Sherlock\Data\NotificationMessage;

interface NotificationChannel
{
    /** slack | telegram | mail | custom */
    public function name(): string;

    /** Whether required credentials / recipients are configured. */
    public function isConfigured(): bool;

    /** May throw; the dispatcher isolates failures per channel. */
    public function send(NotificationMessage $message): void;
}
