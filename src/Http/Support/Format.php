<?php

declare(strict_types=1);

namespace Anabil\Tracing\Http\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * Display formatting for the dashboard views (durations, sizes, dates).
 */
final class Format
{
    public static function ms(float $ms): string
    {
        if ($ms >= 1000) {
            return number_format($ms / 1000, 2).' s';
        }

        if ($ms >= 10) {
            return number_format($ms, 0).' ms';
        }

        return number_format($ms, $ms >= 1 ? 1 : 2).' ms';
    }

    public static function bytes(int $bytes): string
    {
        if ($bytes >= 1_073_741_824) {
            return number_format($bytes / 1_073_741_824, 2).' GB';
        }

        if ($bytes >= 1_048_576) {
            return number_format($bytes / 1_048_576, 1).' MB';
        }

        return number_format($bytes / 1024, 0).' KB';
    }

    public static function number(int|float $value): string
    {
        return is_int($value) ? number_format($value) : number_format($value, 1);
    }

    public static function percent(float $value): string
    {
        return number_format($value, $value > 0 && $value < 10 ? 1 : 0).'%';
    }

    public static function date(?DateTimeInterface $date): string
    {
        return $date === null ? '-' : $date->format('Y-m-d H:i:s');
    }

    public static function ago(?DateTimeInterface $date): string
    {
        return $date === null ? '-' : CarbonImmutable::instance($date)->diffForHumans();
    }

    public static function short(string $value, int $length = 12): string
    {
        return Str::limit($value, $length, '…');
    }

    /**
     * A metric value by its key suffix: *_ms durations, *_bytes / memory sizes, plain numbers otherwise.
     */
    public static function metric(string $key, mixed $value): string
    {
        if (is_numeric($value)) {
            if (str_ends_with($key, '_ms')) {
                return self::ms((float) $value);
            }

            if (str_ends_with($key, '_bytes') || $key === 'memory_peak') {
                return self::bytes((int) $value);
            }

            if (str_ends_with($key, '_ratio') && (float) $value <= 1) {
                return self::percent((float) $value * 100);
            }

            return self::number(is_int($value) || ctype_digit((string) $value) ? (int) $value : (float) $value);
        }

        return Value::display($value);
    }

    private const LABELS = [
        'duration_ms' => 'Duration',
        'db_ms' => 'DB time',
        'db_count' => 'Queries',
        'http_ms' => 'HTTP time',
        'http_count' => 'HTTP calls',
        'memory_peak' => 'Memory peak',
        'total_ms' => 'Total time',
        'max_ms' => 'Slowest',
    ];

    /** "time_ms" => "Time", "avg_db_ms" => "Avg db". */
    public static function label(string $key): string
    {
        if (isset(self::LABELS[$key])) {
            return self::LABELS[$key];
        }

        $key = (string) preg_replace('/_(ms|bytes)$/', '', $key);

        return ucfirst(str_replace('_', ' ', $key));
    }
}
