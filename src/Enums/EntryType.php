<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Enums;

enum EntryType: string
{
    case Request = 'request';
    case Job = 'job';
    case Command = 'command';

    public function label(): string
    {
        return match ($this) {
            self::Request => 'Request',
            self::Job => 'Job',
            self::Command => 'Command',
        };
    }

    /**
     * Issue type raised when this entry type is too slow.
     */
    public function slowIssueType(): IssueType
    {
        return match ($this) {
            self::Request => IssueType::SlowRequest,
            self::Job => IssueType::SlowJob,
            self::Command => IssueType::SlowCommand,
        };
    }
}
