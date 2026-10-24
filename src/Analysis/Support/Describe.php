<?php

declare(strict_types=1);

namespace Anabil\Tracing\Analysis\Support;

use Anabil\Tracing\Data\SourceLocation;
use Anabil\Tracing\Data\TraceData;
use Anabil\Tracing\Enums\EntryType;

/**
 * Deterministic formatting shared by analyzers, the classifier and the interpreter.
 */
final class Describe
{
    /** 912.4 -> "912ms", 2400 -> "2.40s". */
    public static function duration(float $ms): string
    {
        if ($ms < 1000) {
            return round($ms).'ms';
        }

        return sprintf('%.2fs', $ms / 1000);
    }

    /** 950 -> "950", 1500 -> "1.5k", 120000 -> "120k", 2300000 -> "2.3M". */
    public static function count(int $value): string
    {
        return match (true) {
            $value >= 1_000_000 => self::trimZero(sprintf('%.1f', $value / 1_000_000)).'M',
            $value >= 10_000 => round($value / 1000).'k',
            $value >= 1000 => self::trimZero(sprintf('%.1f', $value / 1000)).'k',
            default => (string) $value,
        };
    }

    public static function megabytes(int $bytes): string
    {
        return round($bytes / 1048576).'MB';
    }

    public static function percent(float $ratio): string
    {
        return round($ratio * 100).'%';
    }

    /**
     * Entry name used in titles and issue fingerprints: "GET /orders", job class, command name.
     */
    public static function entry(TraceData $trace): string
    {
        if ($trace->type === EntryType::Request && $trace->method !== null && ! str_starts_with($trace->name, $trace->method.' ')) {
            return $trace->method.' '.$trace->name;
        }

        return $trace->name;
    }

    /**
     * "OrderController@index", or "OrderController.php:42", or null.
     */
    public static function location(?SourceLocation $location): ?string
    {
        if ($location === null) {
            return null;
        }

        if ($location->class !== null && $location->function !== null && ! str_contains($location->class, '@anonymous')) {
            $short = strrchr($location->class, '\\');

            return ($short === false ? $location->class : substr($short, 1)).'@'.$location->function;
        }

        return basename($location->file).':'.$location->line;
    }

    /** " in OrderController@index" (falls back to the entry name). */
    public static function where(TraceData $trace, ?SourceLocation $location): string
    {
        return ' in '.(self::location($location) ?? self::entry($trace));
    }

    /** "`orders`" or "a table" when unknown. */
    public static function table(?string $table): string
    {
        return $table === null ? 'a table' : '`'.$table.'`';
    }

    public static function truncate(string $text, int $length = 120): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)).'…' : $text;
    }

    private static function trimZero(string $number): string
    {
        return str_ends_with($number, '.0') ? substr($number, 0, -2) : $number;
    }
}
