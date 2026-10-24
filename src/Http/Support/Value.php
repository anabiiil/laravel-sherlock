<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Http\Support;

/**
 * Typed reads from the loosely typed JSON arrays stored on traces / issues
 * (details, context). Never throws: malformed values fall back to empty.
 */
final class Value
{
    public static function string(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    public static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    public static function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    public static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * Only the array items of a list (e.g. details.http_calls).
     *
     * @return list<array<array-key, mixed>>
     */
    public static function rows(mixed $value): array
    {
        return array_values(array_filter(self::array($value), is_array(...)));
    }

    /**
     * "file:line" from a stored SourceLocation array, or null.
     */
    public static function location(mixed $value): ?string
    {
        $location = self::array($value);
        $file = self::nullableString($location['file'] ?? null);

        if ($file === null) {
            return null;
        }

        $line = self::nullableInt($location['line'] ?? null);

        return $line === null ? $file : $file.':'.$line;
    }

    /**
     * A scalar / array value as display text (arrays as compact JSON).
     */
    public static function display(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if ($value === null) {
            return '-';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json === false ? '' : $json;
    }
}
