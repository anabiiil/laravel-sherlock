<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Analysis\Analyzers;

use Anabil\Sherlock\Analysis\PlanAwareAnalyzer;
use Anabil\Sherlock\Analysis\Support\Describe;
use Anabil\Sherlock\Contracts\Fingerprinter;
use Anabil\Sherlock\Data\ExplainInsight;
use Anabil\Sherlock\Data\ExplainPlan;
use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Data\QueryRecord;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Enums\ExplainInsightType;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;

/**
 * Driven by EXPLAIN insights of slow SELECTs (full scan, unused index, filesort,
 * temporary table, huge row estimates). One finding per query fingerprint, severity =
 * worst insight; insights below $minSeverity (e.g. a full scan of a 20-row table) are ignored.
 *
 * The issue fingerprint is the query fingerprint alone: a missing index belongs to the
 * query shape, wherever it is called from.
 */
final class MissingIndexAnalyzer implements PlanAwareAnalyzer
{
    public function __construct(
        private readonly Fingerprinter $fingerprinter,
        private readonly Severity $minSeverity = Severity::Warning,
    ) {}

    public function analyze(TraceData $trace): array
    {
        return [];
    }

    public function analyzeWithPlans(TraceData $trace, array $plans): array
    {
        if ($plans === []) {
            return [];
        }

        // Slowest query per explained fingerprint, for location and metrics.
        /** @var array<string, QueryRecord> $slowest */
        $slowest = [];
        foreach ($trace->queries as $query) {
            $fingerprint = $query->fingerprint;
            if ($fingerprint !== null && isset($plans[$fingerprint]) && (! isset($slowest[$fingerprint]) || $query->timeMs > $slowest[$fingerprint]->timeMs)) {
                $slowest[$fingerprint] = $query;
            }
        }

        $findings = [];
        foreach ($plans as $fingerprint => $plan) {
            $insights = array_values(array_filter(
                $plan->insights,
                fn (ExplainInsight $insight): bool => $insight->severity->isAtLeast($this->minSeverity),
            ));

            if ($insights !== []) {
                $findings[] = $this->finding($trace, (string) $fingerprint, $plan, $insights, $slowest[$fingerprint] ?? null);
            }
        }

        return $findings;
    }

    /**
     * @param  non-empty-list<ExplainInsight>  $insights
     */
    private function finding(TraceData $trace, string $fingerprint, ExplainPlan $plan, array $insights, ?QueryRecord $query): Finding
    {
        $severity = Severity::Info;
        $primary = $insights[0];
        foreach ($insights as $insight) {
            if ($insight->severity->isHigherThan($severity)) {
                $severity = $insight->severity;
                $primary = $insight;
            }
        }

        $suggested = null;
        foreach ($insights as $insight) {
            $suggested ??= $insight->suggestedIndex;
        }

        $rows = $primary->estimatedRows !== null ? ' ≈'.Describe::count($primary->estimatedRows).' rows' : '';
        $what = match ($primary->type) {
            ExplainInsightType::Filesort => 'sort without index',
            ExplainInsightType::TemporaryTable => 'temporary table',
            ExplainInsightType::LargeRowsExamined => 'large scan',
            default => 'full scan',
        };

        return new Finding(
            type: IssueType::MissingIndex,
            impactMs: $query !== null ? round($query->timeMs, 3) : 0.0,
            severity: $severity,
            title: 'Missing index on '.Describe::table($primary->table).' ('.$what.$rows.')'
                .($query !== null ? Describe::where($trace, $query->location) : ''),
            description: implode('. ', array_map(static fn (ExplainInsight $i): string => $i->message, $insights)).'.',
            fingerprint: $this->fingerprinter->issue(IssueType::MissingIndex, $fingerprint),
            metrics: [
                'time_ms' => $query !== null ? round($query->timeMs, 2) : null,
                'estimated_rows' => $primary->estimatedRows,
                'table' => $primary->table,
                'suggested_index' => $suggested,
                'insights' => count($insights),
                'driver' => $plan->driver,
            ],
            location: $query?->location,
            sql: $plan->sql,
            queryFingerprint: $fingerprint,
            recommendation: self::recommend($suggested),
        );
    }

    public static function recommend(?string $suggested): string
    {
        if ($suggested === null || preg_match('/^([\w$.]+)\((.+)\)$/', $suggested, $m) !== 1) {
            return 'Add an index covering the columns used in WHERE / JOIN conditions first, then the ORDER BY / GROUP BY columns, '
                .'and confirm with EXPLAIN that the table is no longer fully scanned.';
        }

        $table = $m[1];
        $columns = array_map('trim', explode(',', $m[2]));
        $name = strtolower(str_replace('.', '_', $table).'_'.implode('_', $columns).'_index');
        $php = count($columns) === 1
            ? "'".$columns[0]."'"
            : "['".implode("', '", $columns)."']";

        return sprintf(
            'Add an index on %s: in a migration `$table->index(%s);` (SQL: CREATE INDEX %s ON %s (%s)). '
            .'Equality columns first, then range / ORDER BY columns. Confirm with EXPLAIN that the full scan disappears; '
            .'on large tables create it online (ALGORITHM=INPLACE / CREATE INDEX CONCURRENTLY).',
            $suggested,
            $php,
            $name,
            $table,
            implode(', ', $columns),
        );
    }
}
