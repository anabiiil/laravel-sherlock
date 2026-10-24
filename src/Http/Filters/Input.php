<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Http\Filters;

use Anabil\Sherlock\Support\ReleaseDetector;
use Illuminate\Http\Request;

/**
 * Query-string reads that never trust the shape of the input (arrays are ignored).
 */
final class Input
{
    public static function string(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * A release identifier ([A-Za-z0-9._-/+], at most 64 chars), or null when absent / invalid.
     */
    public static function release(Request $request, string $key = 'release'): ?string
    {
        $value = self::string($request, $key);

        return $value !== '' && ReleaseDetector::normalize($value) === $value ? $value : null;
    }

    public static function page(Request $request): int
    {
        $page = self::string($request, 'page');

        return ctype_digit($page) ? max(1, min((int) $page, 1_000_000)) : 1;
    }
}
