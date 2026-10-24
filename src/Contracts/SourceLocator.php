<?php

declare(strict_types=1);

namespace Anabil\Tracing\Contracts;

use Anabil\Tracing\Data\SourceLocation;

/**
 * Finds the first application frame (skipping vendor/ and this package).
 */
interface SourceLocator
{
    /**
     * @param  list<array<string, mixed>>|null  $frames  debug_backtrace()-shaped frames; null = capture now
     *                                                   (DEBUG_BACKTRACE_IGNORE_ARGS, limited depth)
     */
    public function locate(?array $frames = null): ?SourceLocation;

    /** Absolute path to app-relative path (unchanged when outside the base path). */
    public function relativePath(string $file): string;

    /**
     * Normalize frames for storage: relative paths, no arguments, at most $limit frames.
     *
     * @param  list<array<string, mixed>>  $frames
     * @return list<array{file: string, line: int, class?: string|null, function?: string|null}>
     */
    public function frames(array $frames, int $limit = 30): array;
}
