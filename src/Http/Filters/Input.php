<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Http\Filters;

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

    public static function page(Request $request): int
    {
        $page = self::string($request, 'page');

        return ctype_digit($page) ? max(1, min((int) $page, 1_000_000)) : 1;
    }
}
