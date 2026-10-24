<?php

declare(strict_types=1);

namespace Anabil\Tracing\Data;

use Anabil\Tracing\Enums\EntryType;

/**
 * What is known about a unit when it starts, used by recording policies.
 * Contains no request body / headers except the raw trigger header value.
 */
final readonly class EntryCandidate
{
    /**
     * @param  string  $name  URI path (requests), job class, command name
     * @param  string|null  $triggerHeader  raw value of the X-Tracing header, compared with hash_equals()
     */
    public function __construct(
        public EntryType $type,
        public string $name,
        public ?string $method = null,
        public ?string $path = null,
        public ?string $routeName = null,
        public ?string $triggerHeader = null,
        public int|string|null $userId = null,
    ) {}
}
