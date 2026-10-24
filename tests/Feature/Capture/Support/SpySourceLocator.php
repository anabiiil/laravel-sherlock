<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Tests\Feature\Capture\Support;

use Anabil\Sherlock\Contracts\SourceLocator;
use Anabil\Sherlock\Data\SourceLocation;
use RuntimeException;

final class SpySourceLocator implements SourceLocator
{
    public int $calls = 0;

    public bool $throw = false;

    public function locate(?array $frames = null): ?SourceLocation
    {
        if ($this->throw) {
            throw new RuntimeException('locator exploded');
        }

        $this->calls++;

        return new SourceLocation('app/Spy.php', $this->calls);
    }

    public function relativePath(string $file): string
    {
        return basename($file);
    }

    public function frames(array $frames, int $limit = 30): array
    {
        return [];
    }
}
