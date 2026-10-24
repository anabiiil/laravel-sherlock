<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Http\Support;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Safe Markdown for AI analyses: raw HTML in the source is escaped and unsafe links
 * (javascript:, data:, vbscript:) are dropped by CommonMark.
 */
final class Markdown
{
    public static function render(?string $markdown): ?HtmlString
    {
        if ($markdown === null || trim($markdown) === '') {
            return null;
        }

        return new HtmlString(Str::markdown($markdown, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]));
    }
}
