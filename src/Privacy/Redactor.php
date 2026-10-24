<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Privacy;

use Anabil\Sherlock\Contracts\RedactionRule;
use Anabil\Sherlock\Contracts\Redactor as RedactorContract;
use Anabil\Sherlock\Privacy\Rules\BearerTokenRule;
use Anabil\Sherlock\Privacy\Rules\CardNumberRule;
use Anabil\Sherlock\Privacy\Rules\CustomPatternRule;
use Anabil\Sherlock\Privacy\Rules\EmailRule;
use Anabil\Sherlock\Privacy\Rules\IpAddressRule;
use Anabil\Sherlock\Privacy\Rules\LongSecretRule;
use Anabil\Sherlock\Privacy\Rules\PasswordHashRule;
use Anabil\Sherlock\Privacy\Rules\PhoneRule;
use Anabil\Sherlock\Privacy\Rules\SensitiveKeyRule;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Stringable;
use UnitEnum;

/**
 * Chain of RedactionRule strategies plus key-based and SQL-binding-aware redaction.
 *
 * Fail-closed: a value that cannot be safely represented becomes a placeholder.
 * Per-SQL binding/column maps are memoized (bounded) because the same statements repeat.
 */
final class Redactor implements RedactorContract
{
    public const MAX_STRING_LENGTH = 20000;

    public const MAX_DEPTH = 10;

    private const CACHE_LIMIT = 500;

    /** Longer statements (bulk inserts) are not memoized: 500 of them could hold megabytes in a worker. */
    private const CACHEABLE_SQL_LENGTH = 4096;

    /** Headers never kept, whatever their value. */
    private const DROPPED_HEADERS = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'x-auth-token',
        'x-csrf-token',
        'x-xsrf-token',
        'x-sherlock',
        'php-auth-user',
        'php-auth-pw',
        'php-auth-digest',
        'x-amz-security-token',
    ];

    private const COMPARISON = '/([`"\[]?[\w.]+[`"\]]?)\)?\s*(?:=|<=>|!=|<>|<=|>=|<|>|\bnot\s+like|\blike|\bilike)\s*$/i';

    private const IN_LIST = '/([`"\[]?[\w.]+[`"\]]?)\)?\s+(?:not\s+)?in\s*\(\s*(?:\?\s*,\s*)*$/i';

    private const BETWEEN = '/([`"\[]?[\w.]+[`"\]]?)\)?\s+(?:not\s+)?between\s+(?:\?\s+and\s+)?$/i';

    private const INSERT = '/^\s*(?:insert|replace)(?:\s+ignore)?\s+into\s+[^\s(]+\s*\(([^)]*)\)\s*values\s*/i';

    private const UPSERT_TAIL = '/\bon\s+(?:duplicate\s+key\s+update|conflict)\b/i';

    private const PLACEHOLDER = '/(?<!\?)\?(?!\?)/';

    /** @var array<string, list<string|null>> */
    private array $columnCache = [];

    /**
     * @param  list<RedactionRule>  $rules  text rules, applied in order
     */
    public function __construct(
        private readonly SensitiveKeys $keys,
        private readonly PasswordHashRule $hashes,
        private readonly array $rules,
    ) {}

    /**
     * Default rule chain.
     *
     * @param  iterable<mixed>  $extraKeys
     * @param  iterable<mixed>  $patterns
     */
    public static function make(iterable $extraKeys = [], iterable $patterns = []): self
    {
        $keys = new SensitiveKeys($extraKeys);
        $hashes = new PasswordHashRule;

        $rules = [
            $hashes,
            new BearerTokenRule,
            new SensitiveKeyRule($keys),
            new EmailRule,
            new CardNumberRule,
            new IpAddressRule,
            new PhoneRule,
            new LongSecretRule,
        ];

        $custom = new CustomPatternRule($patterns);
        if (! $custom->isEmpty()) {
            $rules[] = $custom;
        }

        return new self($keys, $hashes, $rules);
    }

    public function keys(): SensitiveKeys
    {
        return $this->keys;
    }

    public function isSensitiveKey(string $key): bool
    {
        return $this->keys->matches($key);
    }

    public function redactString(string $value): string
    {
        if (strlen($value) < 6) {
            return $value;
        }

        if (strlen($value) > self::MAX_STRING_LENGTH) {
            $value = substr($value, 0, self::MAX_STRING_LENGTH).'…[truncated]';
        }

        foreach ($this->rules as $rule) {
            $value = $rule->redact($value);
        }

        return $value;
    }

    public function redactArray(array $data): array
    {
        return $this->redactArrayAt($data, 0);
    }

    public function redactValue(mixed $value): mixed
    {
        return $this->redactValueAt($value, 0);
    }

    public function redactBindings(string $sql, array $bindings): array
    {
        if ($bindings === []) {
            return [];
        }

        $positional = null;
        $result = [];

        foreach ($bindings as $key => $value) {
            if (is_string($key)) {
                $name = ltrim($key, ':');
                $column = $this->namedColumn($sql, $name);
                $sensitive = $this->keys->matches($name) || ($column !== null && $this->keys->matches($column));
            } else {
                $positional ??= $this->positionalColumns($sql);
                $column = $positional[$key] ?? null;
                $sensitive = $column !== null && $this->keys->matches($column);
            }

            $result[$key] = $sensitive ? self::REDACTED : $this->redactBinding($value);
        }

        return $result;
    }

    public function redactHeaders(array $headers): array
    {
        $result = [];

        foreach ($headers as $name => $value) {
            $lower = strtolower((string) $name);
            if (in_array($lower, self::DROPPED_HEADERS, true) || $this->keys->matches($lower)) {
                continue;
            }

            $result[$name] = $this->redactValueAt($value, 1);
        }

        return $result;
    }

    public function redactUrl(string $url): string
    {
        // user:pass@host
        $url = (string) preg_replace('#^([a-z][a-z0-9+.\-]*://)[^/@\s]+@#i', '$1'.self::REDACTED.'@', $url);

        $queryStart = strpos($url, '?');
        if ($queryStart === false) {
            return $this->redactUrlPart($url, false);
        }

        $base = $this->redactUrlPart(substr($url, 0, $queryStart), false);
        $query = substr($url, $queryStart + 1);

        $query = (string) preg_replace_callback('/([^&=]+)=([^&#]*)/', function (array $m): string {
            $key = rawurldecode($m[1]);

            return $m[1].'='.($this->keys->matches($key) ? self::REDACTED : $this->redactUrlPart($m[2], true));
        }, $query);

        return $base.'?'.$query;
    }

    /**
     * Percent-encoded text is judged decoded too ("jane%40example.com" is an email). Clean
     * values keep their encoding; a secret only visible once decoded drops the whole query
     * value, or leaves the path decoded and redacted.
     */
    private function redactUrlPart(string $value, bool $queryValue): string
    {
        $redacted = $this->redactString($value);

        if (! str_contains($value, '%') && ! ($queryValue && str_contains($value, '+'))) {
            return $redacted;
        }

        $decoded = rawurldecode($queryValue ? str_replace('+', ' ', $value) : $value);
        $decodedRedacted = $this->redactString($decoded);

        if ($decodedRedacted === $decoded) {
            return $redacted;
        }

        if (! $queryValue) {
            return $decodedRedacted;
        }

        return $redacted !== $value ? $redacted : self::REDACTED;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function redactArrayAt(array $data, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['[depth limit]'];
        }

        $result = [];
        foreach ($data as $key => $value) {
            $result[$key] = is_string($key) && $this->keys->matches($key)
                ? self::REDACTED
                : $this->redactValueAt($value, $depth + 1);
        }

        return $result;
    }

    private function redactValueAt(mixed $value, int $depth): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value) => $value,
            is_float($value) => is_finite($value) ? $value : (string) $value,
            is_string($value) => $this->safeString($value),
            is_array($value) => $this->redactArrayAt($value, $depth),
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof BackedEnum => is_string($value->value) ? $this->safeString($value->value) : $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof Arrayable => $this->redactArrayAt($this->toArray($value->toArray()), $depth),
            $value instanceof JsonSerializable => $this->redactValueAt($value->jsonSerialize(), $depth + 1),
            $value instanceof Stringable => $this->safeString((string) $value),
            is_object($value) => '[object '.$value::class.']',
            default => '['.get_debug_type($value).']',
        };
    }

    /**
     * @return scalar|null
     */
    private function redactBinding(mixed $value): string|int|float|bool|null
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : (string) $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof BackedEnum) {
            $value = $value->value;
            if (is_int($value)) {
                return $value;
            }
        } elseif ($value instanceof UnitEnum) {
            return $value->name;
        } elseif ($value instanceof Stringable) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return is_object($value) ? '[object '.$value::class.']' : '['.get_debug_type($value).']';
        }

        if ($this->hashes->isHash($value)) {
            return self::REDACTED;
        }

        return $this->safeString($value);
    }

    private function safeString(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            return '[binary]';
        }

        return $this->redactString($value);
    }

    /**
     * @param  mixed  $value
     * @return array<array-key, mixed>
     */
    private function toArray($value): array
    {
        return is_array($value) ? $value : [$value];
    }

    private function namedColumn(string $sql, string $name): ?string
    {
        $pattern = '/([`"\[]?[\w.]+[`"\]]?)\)?\s*(?:=|<=>|!=|<>|<=|>=|<|>|\blike)\s*:'.preg_quote($name, '/').'\b/i';

        return preg_match($pattern, $sql, $m) === 1 ? self::cleanIdentifier($m[1]) : null;
    }

    /**
     * Column name (or null) for each positional placeholder of $sql.
     *
     * @return list<string|null>
     */
    private function positionalColumns(string $sql): array
    {
        if (isset($this->columnCache[$sql])) {
            return $this->columnCache[$sql];
        }

        // Blank out string literals (keeping offsets) so "?" inside them is ignored.
        $masked = (string) preg_replace_callback(
            '/\'(?:[^\'\\\\]|\\\\.|\'\')*\'/s',
            static fn (array $m): string => str_repeat(' ', strlen($m[0])),
            $sql,
        );

        $insertColumns = [];
        $valuesOffset = PHP_INT_MAX;
        $valuesEnd = PHP_INT_MAX;
        if (preg_match(self::INSERT, $masked, $insert, PREG_OFFSET_CAPTURE) === 1) {
            $insertColumns = array_map(self::cleanIdentifier(...), explode(',', $insert[1][0]));
            $valuesOffset = $insert[0][1] + strlen($insert[0][0]);
            if (preg_match(self::UPSERT_TAIL, $masked, $tail, PREG_OFFSET_CAPTURE, $valuesOffset) === 1) {
                $valuesEnd = $tail[0][1];
            }
        }

        $columns = [];
        $insertIndex = 0;
        $count = count($insertColumns);

        preg_match_all(self::PLACEHOLDER, $masked, $placeholders, PREG_OFFSET_CAPTURE);
        foreach ($placeholders[0] as [, $offset]) {
            if ($count > 0 && $offset >= $valuesOffset && $offset < $valuesEnd) {
                $columns[] = $insertColumns[$insertIndex++ % $count];

                continue;
            }

            $prefix = substr($masked, max(0, $offset - 160), min($offset, 160));
            $column = null;
            foreach ([self::COMPARISON, self::IN_LIST, self::BETWEEN] as $pattern) {
                if (preg_match($pattern, $prefix, $m) === 1) {
                    $column = self::cleanIdentifier($m[1]);
                    break;
                }
            }
            $columns[] = $column;
        }

        if (strlen($sql) > self::CACHEABLE_SQL_LENGTH) {
            return $columns;
        }

        if (count($this->columnCache) >= self::CACHE_LIMIT) {
            $this->columnCache = [];
        }

        return $this->columnCache[$sql] = $columns;
    }

    /** "`users`.`password`" => "password" */
    private static function cleanIdentifier(string $identifier): string
    {
        $identifier = trim(str_replace(['`', '"', '[', ']'], '', $identifier));
        $dot = strrpos($identifier, '.');

        return $dot === false ? $identifier : substr($identifier, $dot + 1);
    }
}
