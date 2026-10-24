@extends('sherlock::dashboard.layout')

@use('Anabil\Sherlock\Enums\IssueStatus')
@use('Anabil\Sherlock\Http\Support\Badge')
@use('Anabil\Sherlock\Http\Support\Format')

@section('title', 'Issue #'.$issue->id)

@php
    $card = 'rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900';
    $button = 'rounded-md border px-3 py-1.5 text-sm font-medium';
    $sql = $presenter->sql();
    $insights = $presenter->insights();
    $indexes = $presenter->suggestedIndexes();
    $metrics = $presenter->metrics();
    $traceMetrics = $presenter->traceMetrics();
    $entry = $presenter->entry();
    $exception = $presenter->exception();
    $calls = $presenter->httpCalls();
    $analysis = $presenter->aiAnalysis();
    $breakdown = $presenter->breakdown();
    $queryStats = $presenter->queryStats();
@endphp

@section('content')
    <nav class="mb-3 text-sm"><a href="{{ route('sherlock.issues.index') }}" class="text-zinc-500 hover:underline dark:text-zinc-400">&larr; Issues</a></nav>

    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                @include('sherlock::dashboard.partials.severity', ['severity' => $issue->severity])
                @include('sherlock::dashboard.partials.status', ['status' => $issue->status])
                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $issue->type->label() }}</span>
            </div>
            <h1 class="mt-2 break-words text-xl font-semibold tracking-tight">{{ $issue->title }}</h1>
            <p class="mt-1 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $issue->fingerprint }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($issue->status !== IssueStatus::Resolved)
                <form method="POST" action="{{ route('sherlock.issues.resolve', ['issue' => $issue->id]) }}">
                    @csrf
                    <button type="submit" class="{{ $button }} border-emerald-600 bg-emerald-600 text-white hover:bg-emerald-700">Resolve</button>
                </form>
            @endif
            @if ($issue->status !== IssueStatus::Ignored)
                <form method="POST" action="{{ route('sherlock.issues.ignore', ['issue' => $issue->id]) }}">
                    @csrf
                    <button type="submit" class="{{ $button }} border-zinc-300 bg-white hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800">Ignore</button>
                </form>
            @endif
            @if ($issue->status !== IssueStatus::Open)
                <form method="POST" action="{{ route('sherlock.issues.reopen', ['issue' => $issue->id]) }}">
                    @csrf
                    <button type="submit" class="{{ $button }} border-zinc-300 bg-white hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800">Reopen</button>
                </form>
            @endif
            <form method="POST" action="{{ route('sherlock.issues.reanalyze', ['issue' => $issue->id]) }}">
                @csrf
                <button type="submit" class="{{ $button }} border-sky-600 text-sky-700 hover:bg-sky-50 dark:text-sky-400 dark:hover:bg-sky-950" @if (! $aiEnabled) title="AI analysis is disabled (sherlock.ai.enabled)" @endif>Re-analyze</button>
            </form>
        </div>
    </div>

    {{-- Key numbers --}}
    <dl class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="{{ $card }}"><dt class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Occurrences</dt><dd class="mt-1 text-lg font-semibold tabular-nums">{{ Format::number($issue->occurrences) }}</dd></div>
        <div class="{{ $card }}"><dt class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">First seen</dt><dd class="mt-1 text-sm font-medium" title="{{ Format::date($issue->firstSeenAt) }}">{{ Format::ago($issue->firstSeenAt) }}</dd></div>
        <div class="{{ $card }}"><dt class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Last seen</dt><dd class="mt-1 text-sm font-medium" title="{{ Format::date($issue->lastSeenAt) }}">{{ Format::ago($issue->lastSeenAt) }}</dd></div>
        <div class="{{ $card }}">
            <dt class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ $issue->resolvedAt ? 'Resolved' : ($issue->regressedAt ? 'Regressed' : 'AI analysis') }}</dt>
            <dd class="mt-1 text-sm font-medium">
                @if ($issue->resolvedAt)
                    {{ Format::ago($issue->resolvedAt) }}
                @elseif ($issue->regressedAt)
                    {{ Format::ago($issue->regressedAt) }}
                @else
                    {{ $issue->aiStatus?->label() ?? 'None' }}
                @endif
            </dd>
        </div>
    </dl>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="min-w-0 space-y-6 lg:col-span-2">
            @if ($presenter->description() || $presenter->recommendation())
                <section class="{{ $card }}">
                    @include('sherlock::dashboard.partials.card-title', ['title' => 'What happened'])
                    @if ($presenter->description())
                        <p class="text-sm">{{ $presenter->description() }}</p>
                    @endif
                    @if ($presenter->recommendation())
                        <p class="mt-3 rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-900 dark:bg-sky-950 dark:text-sky-200"><span class="font-semibold">Suggestion:</span> {{ $presenter->recommendation() }}</p>
                    @endif
                </section>
            @endif

            <section class="{{ $card }}">
                @include('sherlock::dashboard.partials.card-title', ['title' => 'Verdict'])
                @if ($presenter->bottleneck())
                    <p class="mb-1 flex items-center gap-2 text-sm font-medium">
                        <span class="{{ Badge::bottleneck($presenter->bottleneck()) }} inline-block h-2.5 w-2.5 rounded-full"></span>
                        {{ $presenter->bottleneck()->label() }}
                    </p>
                @endif
                @if ($presenter->verdictReason())
                    <p class="mb-3 text-sm text-zinc-600 dark:text-zinc-300">{{ $presenter->verdictReason() }}</p>
                @endif
                @include('sherlock::dashboard.partials.breakdown', ['breakdown' => $breakdown])
            </section>

            @if ($sql)
                <section class="{{ $card }}">
                    @include('sherlock::dashboard.partials.card-title', ['title' => 'Query'])
                    <pre class="overflow-x-auto whitespace-pre-wrap break-words rounded-lg bg-zinc-50 p-3 font-mono text-xs leading-relaxed dark:bg-zinc-950"><code>{{ $sql }}</code></pre>
                    @if ($queryStats !== [])
                        <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-xs">
                            @foreach ($queryStats as $label => $value)
                                <div><dt class="inline text-zinc-500 dark:text-zinc-400">{{ $label }}:</dt> <dd class="inline font-medium">{{ $value }}</dd></div>
                            @endforeach
                        </dl>
                    @endif
                </section>
            @endif

            @if ($insights !== [])
                <section class="{{ $card }}">
                    @include('sherlock::dashboard.partials.card-title', ['title' => 'EXPLAIN insights'.($presenter->explainDriver() ? ' ('.$presenter->explainDriver().')' : '')])
                    <ul class="space-y-2 text-sm">
                        @foreach ($insights as $insight)
                            <li class="flex items-start gap-2">
                                @include('sherlock::dashboard.partials.severity', ['severity' => $insight['severity']])
                                <span>
                                    {{ $insight['message'] }}
                                    @if ($insight['rows'] !== null)
                                        <span class="text-xs text-zinc-500 dark:text-zinc-400">(≈{{ Format::number($insight['rows']) }} rows)</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($indexes !== [])
                        <div class="mt-4 rounded-lg bg-emerald-50 p-3 text-sm dark:bg-emerald-950">
                            <p class="font-medium text-emerald-900 dark:text-emerald-200">Suggested index{{ count($indexes) > 1 ? 'es' : '' }}</p>
                            <ul class="mt-1 space-y-0.5 font-mono text-xs text-emerald-900 dark:text-emerald-200">
                                @foreach ($indexes as $index)
                                    <li>{{ $index }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </section>
            @endif

            @if ($exception)
                <section class="{{ $card }}">
                    @include('sherlock::dashboard.partials.card-title', ['title' => 'Exception'])
                    <p class="font-mono text-sm font-semibold text-red-700 dark:text-red-400">{{ $exception['class'] ?? 'Error' }}</p>
                    <p class="mt-1 break-words text-sm">{{ $exception['message'] }}</p>
                    @if ($exception['location'])
                        <p class="mt-1 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $exception['location'] }}</p>
                    @endif
                    @if ($exception['frames'] !== [])
                        <details class="mt-3">
                            <summary class="cursor-pointer text-xs font-medium text-zinc-600 dark:text-zinc-300">Stack trace ({{ count($exception['frames']) }} frames)</summary>
                            <ol class="mt-2 space-y-0.5 overflow-x-auto font-mono text-xs text-zinc-600 dark:text-zinc-400">
                                @foreach ($exception['frames'] as $frame)
                                    <li class="whitespace-nowrap">{{ $frame }}</li>
                                @endforeach
                            </ol>
                        </details>
                    @endif
                </section>
            @endif

            @if ($calls !== [])
                <section class="{{ $card }}">
                    @include('sherlock::dashboard.partials.card-title', ['title' => 'Slowest HTTP calls'])
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

            <section class="{{ $card }}">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">AI analysis</h2>
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ $issue->aiStatus?->label() ?? 'Not analyzed' }}@if ($issue->aiModel) · {{ $issue->aiModel }}@endif @if ($issue->aiAnalyzedAt) · {{ Format::ago($issue->aiAnalyzedAt) }}@endif
                    </span>
                </div>
                @if ($analysis)
                    <div class="tr-md text-sm">{{ $analysis }}</div>
                @else
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $aiEnabled ? 'No analysis yet. Use "Re-analyze" to request one.' : 'AI analysis is disabled. Enable it with SHERLOCK_AI_ENABLED=true.' }}
                    </p>
                @endif
            </section>
        </div>

        <aside class="min-w-0 space-y-6">
            @if ($presenter->location())
                <section class="{{ $card }}">
                    @include('sherlock::dashboard.partials.card-title', ['title' => 'Location'])
                    <p class="break-all font-mono text-xs">{{ $presenter->location() }}</p>
                    @if ($presenter->locationSymbol())
                        <p class="mt-1 break-all font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $presenter->locationSymbol() }}</p>
                    @endif
                </section>
            @endif

            @if ($entry !== [])
                <section class="{{ $card }}">
                    @include('sherlock::dashboard.partials.card-title', ['title' => 'Latest occurrence'])
                    <dl class="space-y-1 text-sm">
                        @foreach ($entry as $label => $value)
                            <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt><dd class="min-w-0 break-all text-right font-mono text-xs">{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                    @if ($presenter->latestTraceId())
                        <a href="{{ route('sherlock.traces.show', ['trace' => $presenter->latestTraceId()]) }}" class="mt-3 inline-block text-xs text-sky-700 hover:underline dark:text-sky-400">Open latest trace &rarr;</a>
                    @endif
                </section>
            @endif

            @if ($metrics !== [] || $traceMetrics !== [])
                <section class="{{ $card }}">
                    @include('sherlock::dashboard.partials.card-title', ['title' => 'Metrics'])
                    <dl class="space-y-1 text-sm">
                        @foreach ($metrics as $label => $value)
                            <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt><dd class="font-medium tabular-nums">{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                    @if ($traceMetrics !== [])
                        <p class="mb-1 mt-4 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Trace</p>
                        <dl class="space-y-1 text-sm">
                            @foreach ($traceMetrics as $label => $value)
                                <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt><dd class="tabular-nums">{{ $value }}</dd></div>
                            @endforeach
                        </dl>
                    @endif
                </section>
            @endif

            <section class="{{ $card }}">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Example traces</h2>
                    @if ($traces->total() > 0)
                        <a href="{{ route('sherlock.traces.index', ['issue' => $issue->id]) }}" class="text-xs text-sky-700 hover:underline dark:text-sky-400">All ({{ $traces->total() }})</a>
                    @endif
                </div>
                @if ($traces->total() === 0)
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">No example traces kept (pruned or ignored).</p>
                @else
                    <ul class="divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                        @foreach ($traces as $trace)
                            <li class="py-1.5">
                                <a href="{{ route('sherlock.traces.show', ['trace' => $trace->id]) }}" class="block truncate font-mono text-xs hover:underline" title="{{ $trace->name }}">{{ $trace->name }}</a>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ Format::ms($trace->durationMs) }} · {{ Format::ago($trace->createdAt) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </aside>
    </div>
@endsection
