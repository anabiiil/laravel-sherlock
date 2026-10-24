<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Support;

use Throwable;

/**
 * Resolves the release / deploy identifier of an application directory without shelling out:
 *
 *  1. a ".release" or "REVISION" file (deploy tools: Envoyer, Capistrano, Deployer...): first line
 *  2. git: .git/HEAD -> the ref file, else packed-refs -> 7-character short SHA (worktrees and
 *     "gitdir:" files are followed; a detached HEAD is used as is)
 *
 * Called from config/sherlock.php, so with "php artisan config:cache" it runs once at deploy
 * and the value is baked into the cached config: zero per-request I/O in production. Without
 * a config cache it costs a couple of tiny file reads per process (memoized).
 */
final class ReleaseDetector
{
    public const SHORT_SHA = 7;

    public const MAX_LENGTH = 64;

    public const FILES = ['.release', 'REVISION'];

    /** @var array<string, string|null> base path => release */
    private static array $memo = [];

    public static function detect(?string $basePath): ?string
    {
        if ($basePath === null || $basePath === '') {
            return null;
        }

        if (array_key_exists($basePath, self::$memo)) {
            return self::$memo[$basePath];
        }

        try {
            $release = self::fromFiles($basePath) ?? self::fromGit($basePath);
        } catch (Throwable) {
            $release = null;
        }

        return self::$memo[$basePath] = $release;
    }

    /** Sanitize a configured / detected value: [A-Za-z0-9._-/+], at most 64 characters; null when empty. */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = (string) preg_replace('/[^A-Za-z0-9._\-\/+]/', '', trim((string) $value));

        return $value === '' ? null : substr($value, 0, self::MAX_LENGTH);
    }

    /** Forget memoized results (tests). */
    public static function flush(): void
    {
        self::$memo = [];
    }

    private static function fromFiles(string $basePath): ?string
    {
        foreach (self::FILES as $file) {
            $contents = self::read($basePath.DIRECTORY_SEPARATOR.$file);
            if ($contents !== null) {
                $line = strtok($contents, "\r\n");
                $release = self::normalize($line === false ? '' : $line);
                if ($release !== null) {
                    return self::isSha($release) ? substr($release, 0, self::SHORT_SHA) : $release;
                }
            }
        }

        return null;
    }

    private static function fromGit(string $basePath): ?string
    {
        $gitDir = self::gitDir($basePath);
        if ($gitDir === null) {
            return null;
        }

        $head = self::read($gitDir.'/HEAD');
        if ($head === null) {
            return null;
        }

        $head = trim($head);
        if (self::isSha($head)) {
            return substr($head, 0, self::SHORT_SHA);
        }

        if (! str_starts_with($head, 'ref:')) {
            return null;
        }

        $ref = trim(substr($head, 4));
        if ($ref === '' || str_contains($ref, '..')) {
            return null;
        }

        // Worktrees keep their refs in the common git dir.
        $common = self::read($gitDir.'/commondir');
        $dirs = [$gitDir];
        if ($common !== null) {
            $common = trim($common);
            $dirs[] = str_starts_with($common, '/') ? $common : $gitDir.'/'.$common;
        }

        foreach ($dirs as $dir) {
            $sha = self::read($dir.'/'.$ref);
            if ($sha !== null && self::isSha(trim($sha))) {
                return substr(trim($sha), 0, self::SHORT_SHA);
            }
        }

        foreach ($dirs as $dir) {
            $sha = self::packedRef($dir.'/packed-refs', $ref);
            if ($sha !== null) {
                return substr($sha, 0, self::SHORT_SHA);
            }
        }

        return null;
    }

    private static function gitDir(string $basePath): ?string
    {
        $git = $basePath.DIRECTORY_SEPARATOR.'.git';

        if (@is_dir($git)) {
            return $git;
        }

        // Worktree / submodule: ".git" is a file "gitdir: <path>".
        $contents = self::read($git);
        if ($contents === null || ! str_starts_with($contents, 'gitdir:')) {
            return null;
        }

        $dir = trim(substr($contents, 7));
        $dir = str_starts_with($dir, '/') ? $dir : $basePath.DIRECTORY_SEPARATOR.$dir;

        return @is_dir($dir) ? $dir : null;
    }

    private static function packedRef(string $path, string $ref): ?string
    {
        $contents = self::read($path, 8_388_608);
        if ($contents === null) {
            return null;
        }

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                continue;
            }

            $parts = explode(' ', trim($line), 2);
            if (count($parts) === 2 && $parts[1] === $ref && self::isSha($parts[0])) {
                return $parts[0];
            }
        }

        return null;
    }

    private static function isSha(string $value): bool
    {
        return preg_match('/^[0-9a-f]{40}([0-9a-f]{24})?$/i', $value) === 1;
    }

    /**
     * @param  positive-int  $maxBytes
     */
    private static function read(string $path, int $maxBytes = 65536): ?string
    {
        if (! @is_file($path) || ! @is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path, false, null, 0, $maxBytes);

        return is_string($contents) && trim($contents) !== '' ? $contents : null;
    }
}
