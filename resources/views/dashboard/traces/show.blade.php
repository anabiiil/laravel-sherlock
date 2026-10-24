@extends('sherlock::dashboard.layout')

@use('Anabil\Sherlock\Http\Support\Badge')
@use('Anabil\Sherlock\Http\Support\Format')

@section('title', $trace->name)

@php
    $card = 'rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900';
    $summary = $presenter->summary();
    $groups = $presenter->groups();
    $rows = $presenter->queryRows();
    $calls = $presenter->httpCalls();
    $logs = $presenter->logs();
    $tags = $presenter->tags();
    $metadata = $presenter->metadata();
    $findings = $presenter->findings();
@endphp

@section('content')
    <nav class="mb-3 text-sm"><a href="{{ route('sherlock.traces.index') }}" class="text-zinc-500 hover:underline dark:text-zinc-400">&larr; Traces</a></nav>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <span class="rounded-md bg-zinc-100 px-2 py-0.5 font-medium dark:bg-zinc-800">{{ $trace->type->label() }}</span>
                <span class="flex items-center gap-1.5"><span class="{{ Badge::bottleneck($trace->bottleneck) }} inline-block h-2 w-2 rounded-full"></span>{{ $trace->bottleneck->label() }}</span>
                @if ($presenter->failed())
                    <span class="rounded-md bg-red-100 px-2 py-0.5 font-medium text-red-800 dark:bg-red-500/15 dark:text-red-300">Failed</span>
                @endif
            </div>
            <h1 class="mt-2 break-all font-mono text-lg font-semibold">{{ $trace->name }}</h1>
            <p class="mt-1 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $trace->id }}</p>
        </div>
        <p class="text-2xl font-semibold tabular-nums">{{ Format::ms($trace->durationMs) }}</p>
    </div>

    <section class="{{ $card }} mt-5">
        @include('sherlock::dashboard.partials.card-title', ['title' => 'Timeline'])
        @if ($presenter->verdictReason())
            <p class="mb-3 text-sm text-zinc-600 dark:text-zinc-300">{{ $presenter->verdictReason() }}</p>
        @endif
        @include('sherlock::dashboard.partials.breakdown', ['breakdown' => $presenter->breakdown()])
    </section>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="min-w-0 space-y-6 lg:col-span-2">
            @if ($findings !== [])
                <section class="{{ $card }}">
                    @include('sherlock::dashboard.partials.card-title', ['title' => 'Findings'])
                    <ul class="space-y-2 text-sm">
                        @foreach ($findings as $finding)
                            <li class="flex items-start gap-2">
                                @include('sherlock::dashboard.partials.severity', ['severity' => $finding['severity']])
                                <div class="min-w-0">
                                    @if ($finding['issue'])
                                        <a href="{{ route('sherlock.issues.show', ['issue' => $finding['issue']->id]) }}" class="font-medium hover:underline">{{ $finding['title'] }}</a>
                                    @else
                                        <span class="font-medium">{{ $finding['title'] }}</span>
                                    @endif
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">· {{ $finding['type'] }}</span>
                                    @if ($finding['description'] !== '')
                                        <p class="text-xs text-zinc-600 dark:text-zinc-400">{{ $finding['description'] }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section class="{{ $card }}">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Queries by fingerprint</h2>
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ Format::number($presenter->storedQueries()) }} stored of {{ Format::number($trace->dbCount) }} executed
                    </span>
                </div>
                @if ($groups === [])
                    @include('sherlock::dashboard.partials.empty', ['message' => 'No queries stored for this trace.'])
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-xs text-zinc-500 dark:text-zinc-400">
                                <tr><th class="py-1 pr-3 font-medium">Query</th><th class="py-1 pr-3 text-right font-medium">Count</th><th class="py-1 pr-3 text-right font-medium">Total</th><th class="py-1 text-right font-medium">Max</th></tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach ($groups as $group)
                                    <tr class="align-top">
                                        <td class="py-2 pr-3">
                                            <code class="block max-w-xl break-words font-mono text-xs">{{ $group['sql'] }}</code>
                                            <span class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                                                @if ($group['n_plus_one'])
                                                    <span class="rounded bg-red-100 px-1.5 py-0.5 font-medium text-red-800 dark:bg-red-500/15 dark:text-red-300">N+1</span>
                                                @endif
                                                <span class="font-mono" title="{{ $group['fingerprint'] }}">{{ Format::short($group['fingerprint']) }}</span>
                                                @foreach ($group['locations'] as $location)
                                                    <span class="font-mono">{{ $location }}</span>
                                                @endforeach
                                            </span>
                                        </td>
                                        <td class="py-2 pr-3 text-right tabular-nums">{{ $group['count'] }}</td>
                                        <td class="py-2 pr-3 text-right tabular-nums">{{ Format::ms($group['total_ms']) }}</td>
                                        <td class="py-2 text-right tabular-nums">{{ Format::ms($group['max_ms']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <details class="mt-4">
                        <summary class="cursor-pointer text-xs font-medium text-zinc-600 dark:text-zinc-300">All stored queries in execution order ({{ count($rows) }})</summary>
                        <div class="mt-2 overflow-x-auto">
                            <table class="min-w-full text-xs">
                                <thead class="text-left text-zinc-500 dark:text-zinc-400">
                                    <tr><th class="py-1 pr-3 font-medium">Offset</th><th class="py-1 pr-3 font-medium">Duration</th><th class="py-1 pr-3 font-medium">SQL</th><th class="py-1 font-medium">Location</th></tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @foreach ($rows as $row)
                                        <tr class="align-top">
                                            <td class="py-1 pr-3 tabular-nums text-zinc-500 dark:text-zinc-400">+{{ Format::ms($row['query']->offsetMs) }}</td>
                                            <td class="py-1 pr-3 tabular-nums">{{ Format::ms($row['query']->durationMs) }}</td>
                                            <td class="max-w-md py-1 pr-3">
                                                <span class="block truncate font-mono" title="{{ $row['query']->normalizedSql }}">{{ $row['query']->normalizedSql }}</span>
                                                @if ($row['n_plus_one'])
                                                    <span class="text-red-700 dark:text-red-400">N+1 · {{ $row['repeats'] }}×</span>
                                                @elseif ($row['repeats'] > 1)
                                                    <span class="text-zinc-500 dark:text-zinc-400">{{ $row['repeats'] }}×</span>
                                                @endif
                                            </td>
                                            <td class="whitespace-nowrap py-1 font-mono text-zinc-500 dark:text-zinc-400">{{ $row['location'] ?? '' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                @endif
            </section>

            @if ($calls !== [])
                <section class="{{ $card }}">
                    @include('sherlock::dashboard.partials.card-title', ['title' => 'HTTP calls'])
                    <ul class="divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                        @foreach ($calls as $call)
                            <li class="flex items-center justify-between gap-3 py-1.5">
                                <span class="min-w-0 truncate font-mono text-xs" title="{{ $call['url'] }}">{{ $call['method'] }} {{ $call['url'] }}</span>
                                <span class="shrink-0 text-xs tabular-nums"><span class="{{ Badge::tone($call['failed']) }}">{{ $call['status'] }}</span> · {{ $call['duration'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($logs !== [])
                <section class="{{ $card }}">
                    @include('sherlock::dashboard.partials.card-title', ['title' => 'Logs & exceptions'])
                    <ul class="space-y-3 text-sm">
                        @foreach ($logs as $log)
                            <li>
                                <p class="flex flex-wrap items-center gap-2">
                                    <span class="{{ Badge::tone($log['error']) }} font-mono text-xs font-semibold uppercase">{{ $log['level'] }}</span>
                                    @if ($log['class'])
                                        <span class="font-mono text-xs">{{ $log['class'] }}</span>
                                    @endif
                                </p>
                                <p class="mt-0.5 break-words">{{ $log['message'] }}</p>
                                @if ($log['location'])
                                    <p class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $log['location'] }}</p>
                                @endif
                                @if ($log['frames'] !== [] || $log['context'])
                                    <details class="mt-1">
                                        <summary class="cursor-pointer text-xs text-zinc-600 dark:text-zinc-300">Details</summary>
                                        @if ($log['frames'] !== [])
                                            <ol class="mt-1 space-y-0.5 overflow-x-auto font-mono text-xs text-zinc-600 dark:text-zinc-400">
                                                @foreach ($log['frames'] as $frame)
                                                    <li class="whitespace-nowrap">{{ $frame }}</li>
                                                @endforeach
                                            </ol>
                                        @endif
                                        @if ($log['context'])
                                            <pre class="mt-2 overflow-x-auto rounded bg-zinc-50 p-2 font-mono text-xs dark:bg-zinc-950">{{ $log['context'] }}</pre>
                                        @endif
                                    </details>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>

        <aside class="min-w-0 space-y-6">
            <section class="{{ $card }}">
                @include('sherlock::dashboard.partials.card-title', ['title' => 'Summary'])
                <dl class="space-y-1 text-sm">
                    @foreach ($summary as $label => $value)
                        <div class="flex justify-between gap-3"><dt class="shrink-0 text-zinc-500 dark:text-zinc-400">{{ $label }}</dt><dd class="min-w-0 break-all text-right">{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </section>

            @if ($tags !== [] || $metadata !== [])
                <section class="{{ $card }}">
                    @include('sherlock::dashboard.partials.card-title', ['title' => 'Tags'])
                    @if ($tags !== [])
                        <ul class="flex flex-wrap gap-1.5">
                            @foreach ($tags as $key => $value)
                                <li class="rounded-md bg-zinc-100 px-2 py-0.5 font-mono text-xs dark:bg-zinc-800">{{ $key }}: {{ $value }}</li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($metadata !== [])
                        <dl class="mt-3 space-y-1 text-sm">
                            @foreach ($metadata as $key => $value)
                                <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ $key }}</dt><dd class="min-w-0 break-all text-right font-mono text-xs">{{ $value }}</dd></div>
                            @endforeach
                        </dl>
                    @endif
                </section>
            @endif
        </aside>
    </div>
@endsection
