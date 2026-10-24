<?php

declare(strict_types=1);

namespace Anabil\Tracing\Support;

use Anabil\Tracing\Contracts\SourceLocator;
use Anabil\Tracing\Data\SourceLocation;

/**
 * First stack frame inside the application base path that is not in vendor/,
 * not in this package and not an entry script. Paths are returned relative.
 */
final class BacktraceSourceLocator implements SourceLocator
{
    private const ENTRY_FILES = ['artisan', 'public/index.php', 'server.php'];

    private readonly string $basePath;

    private readonly string $vendorPath;

    private readonly string $packagePath;

    /** @var list<string> */
    private readonly array $ignorePaths;

    /**
     * @param  list<string>  $ignorePaths  extra relative path prefixes to skip
     */
    public function __construct(
        string $basePath,
        private readonly int $limit = 30,
        array $ignorePaths = [],
        ?string $packagePath = null,
    ) {
        $this->basePath = rtrim(self::normalize($basePath), '/').'/';
        $this->vendorPath = $this->basePath.'vendor/';
        $this->packagePath = rtrim(self::normalize($packagePath ?? dirname(__DIR__)), '/').'/';
        $this->ignorePaths = array_values(array_map(
            static fn (string $path): string => ltrim(self::normalize($path), '/'),
            $ignorePaths,
        ));
    }

    public function locate(?array $frames = null): ?SourceLocation
    {
        $frames ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $this->limit);

        foreach ($frames as $index => $frame) {
            $file = $frame['file'] ?? null;
            if (! is_string($file)) {
                continue;
            }

            $file = self::normalize($file);
            if (! $this->isApplicationFile($file)) {
                continue;
            }

            $relative = substr($file, strlen($this->basePath));
            if ($this->isIgnored($relative)) {
                continue;
            }

            // The enclosing scope of this line is described by the next (outer) frame.
            $outer = $frames[$index + 1] ?? [];
            $class = $outer['class'] ?? null;
            $function = $outer['function'] ?? null;
            $line = $frame['line'] ?? 0;

            return new SourceLocation(
                $relative,
                is_int($line) ? $line : 0,
                is_string($class) ? $class : null,
                is_string($function) ? $function : null,
            );
        }

        return null;
    }

    public function relativePath(string $file): string
    {
        $file = self::normalize($file);

        return str_starts_with($file, $this->basePath) ? substr($file, strlen($this->basePath)) : $file;
    }

    public function frames(array $frames, int $limit = 30): array
    {
        $result = [];

        foreach ($frames as $frame) {
            if (count($result) >= $limit) {
                break;
            }

            $file = $frame['file'] ?? null;
            if (! is_string($file)) {
                continue;
            }

            $line = $frame['line'] ?? 0;
            $class = $frame['class'] ?? null;
            $function = $frame['function'] ?? null;

            $result[] = [
                'file' => $this->relativePath($file),
                'line' => is_int($line) ? $line : 0,
                'class' => is_string($class) ? $class : null,
                'function' => is_string($function) ? $function : null,
            ];
        }

        return $result;
    }

    private function isApplicationFile(string $file): bool
    {
        return str_starts_with($file, $this->basePath)
            && ! str_starts_with($file, $this->vendorPath)
            && ! str_starts_with($file, $this->packagePath);
    }

    private function isIgnored(string $relative): bool
    {
        if (in_array($relative, self::ENTRY_FILES, true)) {
            return true;
        }

        foreach ($this->ignorePaths as $prefix) {
            if ($prefix !== '' && str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
