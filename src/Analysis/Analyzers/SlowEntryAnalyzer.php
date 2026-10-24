<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Analysis\Analyzers;

use Anabil\Sherlock\Analysis\Support\Describe;
use Anabil\Sherlock\Contracts\Analyzer;
use Anabil\Sherlock\Contracts\Fingerprinter;
use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\Severity;

/**
 * Request / job / command duration over the per-entry-type threshold
 * (sherlock.thresholds.entries.{request|job|command}.{warning_ms|critical_ms}).
 */
final class SlowEntryAnalyzer implements Analyzer
{
    public const DEFAULTS = [
        'request' => ['warning_ms' => 1000, 'critical_ms' => 3000],
        'job' => ['warning_ms' => 10000, 'critical_ms' => 60000],
        'command' => ['warning_ms' => 30000, 'critical_ms' => 300000],
    ];

    /** @var array<string, array{warning_ms: float, critical_ms: float}> */
    private readonly array $thresholds;

    /**
     * @param  array<array-key, mixed>  $thresholds  entry type => [warning_ms, critical_ms]
     */
    public function __construct(
        private readonly Fingerprinter $fingerprinter,
        array $thresholds = self::DEFAULTS,
    ) {
        $resolved = [];
        foreach (self::DEFAULTS as $type => $defaults) {
            $given = is_array($thresholds[$type] ?? null) ? $thresholds[$type] : [];
            $resolved[$type] = [
                'warning_ms' => is_numeric($given['warning_ms'] ?? null) ? (float) $given['warning_ms'] : (float) $defaults['warning_ms'],
                'critical_ms' => is_numeric($given['critical_ms'] ?? null) ? (float) $given['critical_ms'] : (float) $defaults['critical_ms'],
            ];
        }
        $this->thresholds = $resolved;
    }

    public function analyze(TraceData $trace): array
    {
        $threshold = $this->thresholds[$trace->type->value];
        if ($trace->durationMs < $threshold['warning_ms']) {
            return [];
        }

        $type = $trace->type->slowIssueType();
        $entry = Describe::entry($trace);
        $kind = strtolower($trace->type->label());
        [$hint, $dominant] = $this->hint($trace);

        return [new Finding(
            type: $type,
            severity: $trace->durationMs >= $threshold['critical_ms'] ? Severity::Critical : Severity::Warning,
            title: 'Slow '.$kind.' '.$entry.' ('.Describe::duration($trace->durationMs).')',
            description: sprintf(
                'The %s took %s (threshold %s): database %s in %d queries, external HTTP %s in %d calls.',
                $kind,
                Describe::duration($trace->durationMs),
                Describe::duration($threshold['warning_ms']),
                Describe::duration($trace->dbMs),
                $trace->queryCount(),
                Describe::duration($trace->httpMs),
                $trace->httpCount(),
            ),
            fingerprint: $this->fingerprinter->issue($type, null, null, $entry),
            metrics: [
                'duration_ms' => round($trace->durationMs, 2),
                'threshold_ms' => $threshold['warning_ms'],
                'db_ms' => round($trace->dbMs, 2),
                'db_count' => $trace->queryCount(),
                'http_ms' => round($trace->httpMs, 2),
                'http_count' => $trace->httpCount(),
                'dominant' => $dominant,
            ],
            recommendation: $hint.($trace->type === EntryType::Request
                ? ' If the work cannot be made faster, move it to a queued job and respond immediately.'
                : ' Split long work into smaller chunked or batched jobs so each one stays short and retryable.'),
        )];
    }

    /**
     * Cheap pointer to where the time went (the verdict carries the full breakdown).
     *
     * @return array{0: string, 1: string}
     */
    private function hint(TraceData $trace): array
    {
        $duration = max($trace->durationMs, 0.001);

        if ($trace->dbMs / $duration >= 0.5) {
            return [sprintf(
                'Most of the time (%s) is spent in the database: look at the slow query, N+1 and missing index findings of this trace first.',
                Describe::percent($trace->dbMs / $duration),
            ), 'database'];
        }

        if ($trace->httpMs / $duration >= 0.4) {
            return [sprintf(
                'Most of the time (%s) is spent waiting for external HTTP calls: set short timeouts, cache responses, or call them asynchronously.',
                Describe::percent($trace->httpMs / $duration),
            ), 'external_http'];
        }

        return ['Little time is spent in the database or external calls, so the time goes to application code: '
            .'profile the handler for heavy loops, large collections, serialization or file processing.', 'application'];
    }
}
