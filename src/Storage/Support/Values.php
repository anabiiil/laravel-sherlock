<?php

declare(strict_types=1);

namespace Anabil\Tracing\Storage\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Conversions between PHP values and storage columns. Dates are stored as DATETIME in
 * the PHP default timezone (Laravel sets it to app.timezone) and read back the same way.
 */
final class Values
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;

    public static function date(DateTimeInterface $date): string
    {
        return DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format(self::DATE_FORMAT);
    }

    public static function toDate(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(self::DATE_FORMAT, substr($value, 0, 19), new DateTimeZone(date_default_timezone_get()));

        return $date === false ? new DateTimeImmutable($value) : $date;
    }

    public static function json(mixed $value): string
    {
        $json = json_encode($value, self::JSON_FLAGS);

        return $json === false ? 'null' : $json;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public static function fromJson(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    public static function truncate(?string $value, int $length): ?string
    {
        if ($value === null || mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length - 1).'…';
    }

    public static function int(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function float(mixed $value, float $default = 0.0): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }

    public static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    public static function string(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    public static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /** Auto-increment ids come back as int (or numeric strings on some drivers). */
    public static function id(mixed $value): int|string
    {
        if (is_int($value)) {
            return $value;
        }

        $string = self::string($value);

        return ctype_digit($string) ? (int) $string : $string;
    }

    public static function userId(mixed $value): int|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::id($value);
    }
}
