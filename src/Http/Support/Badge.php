<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Http\Support;

use Anabil\Sherlock\Enums\Bottleneck;
use Anabil\Sherlock\Enums\IssueStatus;
use Anabil\Sherlock\Enums\Severity;

/**
 * Tailwind classes for badges and chart segments, kept out of the views.
 */
final class Badge
{
    public static function severity(Severity $severity): string
    {
        return match ($severity) {
            Severity::Critical => 'bg-red-100 text-red-800 ring-red-600/20 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-400/30',
            Severity::Warning => 'bg-amber-100 text-amber-800 ring-amber-600/20 dark:bg-amber-500/15 dark:text-amber-300 dark:ring-amber-400/30',
            Severity::Info => 'bg-sky-100 text-sky-800 ring-sky-600/20 dark:bg-sky-500/15 dark:text-sky-300 dark:ring-sky-400/30',
        };
    }

    public static function status(IssueStatus $status): string
    {
        return match ($status) {
            IssueStatus::Open => 'bg-indigo-100 text-indigo-800 ring-indigo-600/20 dark:bg-indigo-500/15 dark:text-indigo-300 dark:ring-indigo-400/30',
            IssueStatus::Resolved => 'bg-emerald-100 text-emerald-800 ring-emerald-600/20 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-400/30',
            IssueStatus::Ignored => 'bg-zinc-100 text-zinc-700 ring-zinc-500/20 dark:bg-zinc-500/15 dark:text-zinc-300 dark:ring-zinc-400/30',
        };
    }

    public static function bottleneck(Bottleneck $bottleneck): string
    {
        return match ($bottleneck) {
            Bottleneck::Database => 'bg-sky-500',
            Bottleneck::ExternalService => 'bg-violet-500',
            Bottleneck::Cache => 'bg-amber-500',
            Bottleneck::Bootstrap => 'bg-zinc-400',
            Bottleneck::Application => 'bg-emerald-500',
            Bottleneck::Mixed => 'bg-rose-400',
            Bottleneck::Unknown => 'bg-zinc-300 dark:bg-zinc-600',
        };
    }

    /** HTTP status code / log level tone. */
    public static function tone(bool $bad): string
    {
        return $bad
            ? 'text-red-700 dark:text-red-400'
            : 'text-zinc-700 dark:text-zinc-300';
    }
}
