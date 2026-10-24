<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Contracts;

/**
 * Removes sensitive data. Applied at capture time in every mode, and again on
 * every outbound payload (AI, Slack, Telegram, Mail).
 */
interface Redactor
{
    public const REDACTED = '[REDACTED]';

    /** Mask secrets inside free text (emails, tokens, hashes, cards, IPs, key=value pairs...). */
    public function redactString(string $value): string;

    /**
     * Recursive: sensitive keys are replaced by [REDACTED], string values pass redactString().
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function redactArray(array $data): array;

    /** Any value (scalar, array, object) to a safe, storable representation. */
    public function redactValue(mixed $value): mixed;

    /**
     * Redact query bindings: values bound to sensitive columns and hash-shaped values
     * become [REDACTED]; other strings pass redactString(). Positional and named.
     *
     * @param  array<array-key, mixed>  $bindings
     * @return array<array-key, scalar|null>
     */
    public function redactBindings(string $sql, array $bindings): array;

    /**
     * Drop sensitive headers (Authorization, Cookie, X-Api-Key, ...), redact the rest.
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    public function redactHeaders(array $headers): array;

    /** Mask sensitive query-string values of a URL. */
    public function redactUrl(string $url): string;

    public function isSensitiveKey(string $key): bool;
}
