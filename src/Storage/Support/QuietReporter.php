<?php

declare(strict_types=1);

namespace Anabil\Tracing\Storage\Support;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Logs a storage failure once per distinct error per process, at warning level (below
 * the captured log level, so it never feeds back into tracing). A broken tracing
 * database must never flood the host application's logs.
 */
final class QuietReporter
{
    private const MAX_KEYS = 50;

    /** @var array<string, true> */
    private static array $reported = [];

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function report(string $what, Throwable $e): void
    {
        $key = $what."\0".$e::class."\0".$e->getMessage();

        if (isset(self::$reported[$key]) || count(self::$reported) >= self::MAX_KEYS) {
            return;
        }

        self::$reported[$key] = true;

        try {
            $this->logger?->warning('[tracing] '.$what.': '.$e->getMessage(), ['exception' => $e::class]);
        } catch (Throwable) {
            // Logging must never break the host application either.
        }
    }

    /** Test helper: forget what was reported. */
    public static function flush(): void
    {
        self::$reported = [];
    }
}
