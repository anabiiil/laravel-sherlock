<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Analysis\Analyzers;

use Anabil\Sherlock\Analysis\Support\Describe;
use Anabil\Sherlock\Contracts\Analyzer;
use Anabil\Sherlock\Contracts\Fingerprinter;
use Anabil\Sherlock\Contracts\SourceLocator;
use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Data\LogRecord;
use Anabil\Sherlock\Data\SourceLocation;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;

/**
 * Exceptions and error logs. One finding per distinct exception class + location
 * (or log level + location + message template).
 *
 * Issue fingerprints never include the message of an exception: class + relative
 * file + line of the first application frame (falling back to the throw site).
 * Exceptions are always critical; log levels map to severities via $logLevels
 * (sherlock.thresholds.log_levels), levels not in the map are ignored.
 */
final class ExceptionAnalyzer implements Analyzer
{
    public const DEFAULT_LOG_LEVELS = [
        'error' => 'warning',
        'critical' => 'critical',
        'alert' => 'critical',
        'emergency' => 'critical',
    ];

    /** Class short name => deterministic hint. */
    private const HINTS = [
        'QueryException' => 'A database query failed: check the SQL, missing columns/tables (pending migrations), constraint violations and connection limits.',
        'ModelNotFoundException' => 'A model lookup (findOrFail / route model binding) found no record: validate the id or handle the missing record explicitly.',
        'ConnectionException' => 'An HTTP connection failed or timed out: set explicit timeouts, add bounded retries and handle the outage gracefully.',
        'RequestException' => 'An outgoing HTTP request returned an error status: check the response, the credentials and the remote service status.',
        'ValidationException' => 'Validation failed: usually expected user input errors; if unexpected, check the rules and the payload shape.',
        'AuthorizationException' => 'An authorization check denied the action: verify the policy / gate logic for this user and resource.',
        'TokenMismatchException' => 'CSRF token mismatch: check session configuration (domain, secure cookies, lifetime) and that forms send the token.',
        'MaxAttemptsExceededException' => 'A job exceeded its attempts: check why earlier attempts failed or timed out, and tune tries / backoff / timeout.',
        'TimeoutExceededException' => 'A job ran longer than its timeout: make it faster, split it, or raise the timeout below the queue retry_after.',
        'TypeError' => 'A value of the wrong type was passed: check nullable values, casts and input coming from requests or the database.',
        'ErrorException' => 'A PHP warning/notice was promoted to an exception: check for undefined indexes or variables and null values.',
    ];

    /** @var array<string, Severity> */
    private readonly array $logLevels;

    /**
     * @param  array<array-key, mixed>  $logLevels  PSR-3 level => severity name
     */
    public function __construct(
        private readonly Fingerprinter $fingerprinter,
        private readonly ?SourceLocator $locator = null,
        array $logLevels = self::DEFAULT_LOG_LEVELS,
    ) {
        $levels = [];
        foreach ($logLevels as $level => $severity) {
            if (is_string($level)) {
                $levels[strtolower($level)] = Severity::fromConfig($severity);
            }
        }
        $this->logLevels = $levels;
    }

    public function analyze(TraceData $trace): array
    {
        if ($trace->logs === []) {
            return [];
        }

        /** @var array<string, array{log: LogRecord, location: SourceLocation|null, count: int, extra: string, type: IssueType, severity: Severity}> $groups */
        $groups = [];

        foreach ($trace->logs as $log) {
            if ($log->isException()) {
                $type = IssueType::Exception;
                $severity = Severity::Critical;
                $extra = (string) $log->exceptionClass;
            } else {
                $severity = $this->logLevels[strtolower($log->level)] ?? null;
                if ($severity === null) {
                    continue;
                }
                $type = IssueType::LogError;
                $extra = strtolower($log->level).':'.self::messageTemplate($log->message);
            }

            $location = $this->locationOf($log);
            $key = $type->value."\0".$extra."\0".($location?->toString() ?? '');

            if (isset($groups[$key])) {
                $groups[$key]['count']++;
            } else {
                $groups[$key] = ['log' => $log, 'location' => $location, 'count' => 1, 'extra' => $extra, 'type' => $type, 'severity' => $severity];
            }
        }

        $findings = [];
        foreach ($groups as $group) {
            $findings[] = $group['type'] === IssueType::Exception
                ? $this->exceptionFinding($trace, $group['log'], $group['location'], $group['count'], $group['extra'])
                : $this->logFinding($trace, $group['log'], $group['location'], $group['count'], $group['extra'], $group['severity']);
        }

        return $findings;
    }

    private function exceptionFinding(TraceData $trace, LogRecord $log, ?SourceLocation $location, int $count, string $class): Finding
    {
        $short = self::shortClass($class);
        $where = $location?->toString() ?? Describe::entry($trace);

        return new Finding(
            type: IssueType::Exception,
            severity: Severity::Critical,
            title: $short.' in '.$where,
            description: Describe::truncate($log->message, 500).($count > 1 ? sprintf(' (thrown %d times in this %s)', $count, strtolower($trace->type->label())) : ''),
            // Entry name excluded: the same bug reached from two routes is one issue.
            fingerprint: $this->fingerprinter->issue(IssueType::Exception, null, $location, $location === null ? Describe::entry($trace) : null, $class),
            metrics: [
                'exception' => $class,
                'count' => $count,
                'level' => $log->level,
            ],
            location: $location,
            recommendation: (self::HINTS[$short] ?? 'Inspect the stack trace starting at '.$where.' and handle the failing case explicitly (validate input, guard nulls, catch and report expected failures).')
                .' Add a regression test that reproduces it before fixing.',
        );
    }

    private function logFinding(TraceData $trace, LogRecord $log, ?SourceLocation $location, int $count, string $extra, Severity $severity): Finding
    {
        $where = $location?->toString() ?? Describe::entry($trace);

        return new Finding(
            type: IssueType::LogError,
            severity: $severity,
            title: 'Log '.strtolower($log->level).' in '.$where.': '.Describe::truncate($log->message, 80),
            description: Describe::truncate($log->message, 500).($count > 1 ? sprintf(' (logged %d times in this %s)', $count, strtolower($trace->type->label())) : ''),
            fingerprint: $this->fingerprinter->issue(IssueType::LogError, null, $location, $location === null ? Describe::entry($trace) : null, $extra),
            metrics: [
                'level' => strtolower($log->level),
                'count' => $count,
            ],
            location: $location,
            recommendation: 'Look at the code logging this message at '.$where.' and the condition that triggers it; '
                .'if it reflects a real failure, handle or fix the cause, otherwise lower the log level so it does not hide real errors.',
        );
    }

    /**
     * First application frame (not vendor/), else the throw / log site. Always relative.
     */
    private function locationOf(LogRecord $log): ?SourceLocation
    {
        foreach ($log->frames as $frame) {
            $file = $this->relative($frame['file']);
            if ($file !== '' && ! str_starts_with($file, 'vendor/') && $frame['line'] > 0) {
                return new SourceLocation($file, $frame['line'], $frame['class'] ?? null, $frame['function'] ?? null);
            }
        }

        if ($log->file !== null && $log->line !== null) {
            return new SourceLocation($this->relative($log->file), $log->line);
        }

        return null;
    }

    private function relative(string $file): string
    {
        $file = str_replace('\\', '/', $file);

        return $this->locator?->relativePath($file) ?? $file;
    }

    /**
     * Message with the variable parts removed (numbers, quoted values, ids), for grouping log errors.
     */
    public static function messageTemplate(string $message): string
    {
        $template = (string) preg_replace(
            ['/\'[^\']*\'|"[^"]*"/', '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '/\b[0-9a-f]{16,}\b/i', '/\d+(?:\.\d+)?/', '/\s+/'],
            ['?', '?', '?', '?', ' '],
            $message,
        );

        return mb_substr(trim($template), 0, 200);
    }

    private static function shortClass(string $class): string
    {
        $short = strrchr($class, '\\');

        return $short === false ? $class : substr($short, 1);
    }
}
