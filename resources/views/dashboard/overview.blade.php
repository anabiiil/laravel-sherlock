@extends('sherlock::dashboard.layout')

@use('Anabil\Sherlock\Http\Support\Badge')
@use('Anabil\Sherlock\Http\Support\Format')

@section('title', 'Overview')

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Overview</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $period->label() }}</p>
        </div>
        @include('sherlock::dashboard.partials.period', ['route' => 'sherlock.overview'])
    </div>

    {{-- Counters --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <a href="{{ route('sherlock.traces.index') }}" class="rounded-xl border border-zinc-200 bg-white p-4 hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-700">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Traces</p>
            <p class="mt-1 text-2xl font-semibold">{{ Format::number($totalTraces) }}</p>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                @foreach ($traceCounts as $type => $count)
                    {{ ucfirst($type) }}s {{ Format::number($count) }}@if (! $loop->last) · @endif
                @endforeach
            </p>
        </a>
        @foreach ($severities as $row)
            <a href="{{ route('sherlock.issues.index', ['severity' => $row['severity']->value]) }}" class="rounded-xl border border-zinc-200 bg-white p-4 hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-700">
                <p class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                    Open @include('sherlock::dashboard.partials.severity', ['severity' => $row['severity']])
                </p>
                <p class="mt-1 text-2xl font-semibold">{{ Format::number($row['count']) }}</p>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">open issues</p>
            </a>
        @endforeach
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        {{-- Bottlenecks --}}
        <section class="min-w-0 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            @include('sherlock::dashboard.partials.card-title', ['title' => 'Where the time goes'])
            @if ($bottlenecks === [])
                @include('sherlock::dashboard.partials.empty', ['message' => 'No traces in this period.'])
            @else
                <div class="flex h-3 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800" role="img" aria-label="Bottleneck distribution">
                    @foreach ($bottlenecks as $row)
                        <div class="{{ Badge::bottleneck($row['bottleneck']) }} h-full" style="width: {{ $row['percent'] }}%" title="{{ $row['bottleneck']->label() }}: {{ $row['count'] }}"></div>
                    @endforeach
                </div>
                <ul class="mt-3 divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                    @foreach ($bottlenecks as $row)
                        <li class="flex items-center justify-between py-1.5">
                            <a href="{{ route('sherlock.traces.index', ['bottleneck' => $row['bottleneck']->value]) }}" class="flex items-center gap-2 hover:underline">
                                <span class="{{ Badge::bottleneck($row['bottleneck']) }} inline-block h-2.5 w-2.5 rounded-full"></span>
                                {{ $row['bottleneck']->label() }}
                            </a>
                            <span class="tabular-nums text-zinc-600 dark:text-zinc-400">{{ Format::number($row['count']) }} · {{ Format::percent($row['percent']) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- New / regressed issues --}}
        <section class="min-w-0 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            @include('sherlock::dashboard.partials.card-title', ['title' => 'New & regressed issues'])
            @if ($newIssues === [] && $regressedIssues === [])
                @include('sherlock::dashboard.partials.empty', ['message' => 'No new or regressed issues in this period.'])
            @else
                <ul class="divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                    @foreach ($regressedIssues as $issue)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <a href="{{ route('sherlock.issues.show', ['issue' => $issue->id]) }}" class="min-w-0 truncate hover:underline">{{ $issue->title }}</a>
                            <span class="flex shrink-0 items-center gap-2">
                                <span class="rounded-md bg-rose-100 px-2 py-0.5 text-xs font-medium text-rose-800 dark:bg-rose-500/15 dark:text-rose-300">Regressed</span>
                                @include('sherlock::dashboard.partials.severity', ['severity' => $issue->severity])
                            </span>
                        </li>
                    @endforeach
                    @foreach ($newIssues as $issue)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <a href="{{ route('sherlock.issues.show', ['issue' => $issue->id]) }}" class="min-w-0 truncate hover:underline">{{ $issue->title }}</a>
                            <span class="flex shrink-0 items-center gap-2">
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ Format::ago($issue->firstSeenAt) }}</span>
                                @include('sherlock::dashboard.partials.severity', ['severity' => $issue->severity])
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Top issues by impact --}}
        <section class="min-w-0 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Top issues by impact</h2>
                <a href="{{ route('sherlock.issues.index', ['sort' => 'impact', 'status' => 'all']) }}" class="text-xs text-sky-700 hover:underline dark:text-sky-400">All by impact</a>
            </div>
            @if ($impactIssues === [])
                @include('sherlock::dashboard.partials.empty', ['message' => 'No issue cost measurable time in this period.', 'hint' => 'Impact is the time every occurrence cost (repeated N+1 queries, slow query time, excess over the slow threshold...), summed per issue.'])
            @else
                <ul class="divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                    @foreach ($impactIssues as $issue)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <span class="min-w-0">
                                <a href="{{ route('sherlock.issues.show', ['issue' => $issue->id]) }}" class="block truncate hover:underline" title="{{ $issue->title }}">{{ $issue->title }}</a>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $issue->type->label() }} · {{ Format::number($issue->occurrences) }}×</span>
                            </span>
                            <span class="flex shrink-0 items-center gap-2">
                                <span class="font-medium tabular-nums" title="Cumulative time cost of every occurrence">{{ Format::human($issue->impactMs) }}</span>
                                @include('sherlock::dashboard.partials.severity', ['severity' => $issue->severity])
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Latest deploy --}}
        <section class="min-w-0 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Latest deploy</h2>
                <a href="{{ route('sherlock.deploys.index') }}" class="text-xs text-sky-700 hover:underline dark:text-sky-400">Deploys</a>
            </div>
            @if ($deploy === null)
                @include('sherlock::dashboard.partials.empty', ['message' => 'Not enough releases to compare yet.', 'hint' => 'Set SHERLOCK_RELEASE (or deploy from git) and run "php artisan config:cache" on deploy.'])
            @else
                <p @class(['text-sm font-medium', 'text-red-700 dark:text-red-400' => $deploy->hasRegressions(), 'text-emerald-700 dark:text-emerald-400' => ! $deploy->hasRegressions()])>{{ \Anabil\Sherlock\Digest\DeploySummary::headline($deploy) }}</p>
                <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                    <span class="font-mono">{{ $deploy->current }}</span> vs <span class="font-mono">{{ $deploy->previous }}</span>@if ($deploy->currentRelease) · deployed {{ Format::ago($deploy->currentRelease->firstSeenAt) }}@endif
                </p>
                @php($slower = array_slice($deploy->slower(), 0, 3))
                @if ($slower !== [])
                    <ul class="mt-2 divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                        @foreach ($slower as $delta)
                            <li class="flex items-center justify-between gap-3 py-1.5">
                                <span class="min-w-0 truncate font-mono text-xs" title="{{ $delta->name }}">{{ $delta->name }}</span>
                                <span class="shrink-0 text-xs tabular-nums text-red-700 dark:text-red-400">{{ Format::signedRatio($delta->deltaRatio) }} ({{ Format::signedMs($delta->deltaAvgMs) }})</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </section>

        {{-- Slowest entries --}}
        <section class="min-w-0 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            @include('sherlock::dashboard.partials.card-title', ['title' => 'Slowest routes, jobs & commands'])
            @if ($slowest === [])
                @include('sherlock::dashboard.partials.empty', ['message' => 'No traces in this period.'])
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs text-zinc-500 dark:text-zinc-400">
                            <tr><th class="py-1 pr-3 font-medium">Entry</th><th class="py-1 pr-3 text-right font-medium">Count</th><th class="py-1 pr-3 text-right font-medium">Avg</th><th class="py-1 text-right font-medium">Max</th></tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($slowest as $entry)
                                <tr>
                                    <td class="max-w-xs py-1.5 pr-3">
                                        <a href="{{ route('sherlock.traces.index', ['type' => $entry->type->value, 'name' => $entry->name]) }}" class="block truncate font-mono text-xs hover:underline" title="{{ $entry->name }}">{{ $entry->name }}</a>
                                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $entry->type->label() }} · {{ Format::number($entry->avgQueries) }} queries avg</span>
                                    </td>
                                    <td class="py-1.5 pr-3 text-right tabular-nums">{{ Format::number($entry->count) }}</td>
                                    <td class="py-1.5 pr-3 text-right tabular-nums">{{ Format::ms($entry->avgMs) }}</td>
                                    <td class="py-1.5 text-right tabular-nums">{{ Format::ms($entry->maxMs) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        {{-- Top queries --}}
        <section class="min-w-0 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Top queries by total time</h2>
                <a href="{{ route('sherlock.queries.index', ['period' => $period->key]) }}" class="text-xs text-sky-700 hover:underline dark:text-sky-400">All queries</a>
            </div>
            @if ($topQueries === [])
                @include('sherlock::dashboard.partials.empty', ['message' => 'No queries recorded in this period.'])
            @else
                <ul class="divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                    @foreach ($topQueries as $query)
                        <li class="py-2">
                            <p class="truncate font-mono text-xs" title="{{ $query->normalizedSql }}">{{ $query->normalizedSql }}</p>
                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ Format::ms($query->totalMs) }} total · {{ Format::number($query->count) }}× · {{ Format::ms($query->avgMs) }} avg
                            </p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
@endsection
