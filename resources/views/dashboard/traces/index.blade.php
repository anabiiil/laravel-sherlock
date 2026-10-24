@extends('sherlock::dashboard.layout')

@use('Anabil\Sherlock\Http\Support\Badge')
@use('Anabil\Sherlock\Http\Support\Format')

@section('title', 'Traces')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold tracking-tight">Traces</h1>
        @if ($filters->issueId !== null)
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Example traces of
                @if ($issue)
                    <a href="{{ route('sherlock.issues.show', ['issue' => $issue->id]) }}" class="font-medium hover:underline">{{ $issue->title }}</a>
                @else
                    issue #{{ $filters->issueId }}
                @endif
                · <a href="{{ route('sherlock.traces.index') }}" class="text-sky-700 hover:underline dark:text-sky-400">clear</a>
            </p>
        @endif
    </div>

    <form method="GET" action="{{ route('sherlock.traces.index') }}" class="mb-4 grid grid-cols-2 gap-2 rounded-xl border border-zinc-200 bg-white p-3 text-sm sm:grid-cols-3 lg:grid-cols-6 dark:border-zinc-800 dark:bg-zinc-900">
        @if ($filters->issueId !== null)
            <input type="hidden" name="issue" value="{{ $filters->issueId }}">
        @endif
        <label class="col-span-2 sm:col-span-3 lg:col-span-2">
            <span class="sr-only">Name</span>
            <input type="search" name="name" value="{{ $filters->name }}" placeholder="Exact name, e.g. GET /users/{id}" maxlength="255"
                   class="w-full rounded-md border border-zinc-300 bg-white px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-950">
        </label>
        <label>
            <span class="sr-only">Type</span>
            <select name="type" class="w-full rounded-md border border-zinc-300 bg-white px-2 py-1.5 dark:border-zinc-700 dark:bg-zinc-950">
                <option value="">All types</option>
                @foreach ($types as $type)
                    <option value="{{ $type->value }}" @selected($filters->type === $type)>{{ $type->label() }}s</option>
                @endforeach
            </select>
        </label>
        <label>
            <span class="sr-only">Bottleneck</span>
            <select name="bottleneck" class="w-full rounded-md border border-zinc-300 bg-white px-2 py-1.5 dark:border-zinc-700 dark:bg-zinc-950">
                <option value="">Any bottleneck</option>
                @foreach ($bottlenecks as $bottleneck)
                    <option value="{{ $bottleneck->value }}" @selected($filters->bottleneck === $bottleneck)>{{ $bottleneck->label() }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span class="sr-only">Minimum duration (ms)</span>
            <input type="number" name="min_ms" min="0" step="1" value="{{ $filters->minDurationMs }}" placeholder="Min ms"
                   class="w-full rounded-md border border-zinc-300 bg-white px-3 py-1.5 dark:border-zinc-700 dark:bg-zinc-950">
        </label>
        <div class="flex gap-2">
            <label class="min-w-0 flex-1">
                <span class="sr-only">Sort</span>
                <select name="sort" class="w-full rounded-md border border-zinc-300 bg-white px-2 py-1.5 dark:border-zinc-700 dark:bg-zinc-950">
                    @foreach ($sorts as $value => $label)
                        <option value="{{ $value }}" @selected($filters->sort === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <button type="submit" class="rounded-md bg-zinc-900 px-3 py-1.5 font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">Filter</button>
        </div>
    </form>

    @if ($traces->total() === 0)
        @include('sherlock::dashboard.partials.empty', [
            'message' => 'No traces match these filters.',
            'hint' => 'In production mode only problematic or sampled units are stored; use "php artisan sherlock:watch" for full tracing.',
        ])
    @else
        <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                <thead class="bg-zinc-50 text-left text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-2 font-medium">Entry</th>
                        <th class="px-4 py-2 text-right font-medium">Duration</th>
                        <th class="hidden px-4 py-2 text-right font-medium md:table-cell">Queries</th>
                        <th class="hidden px-4 py-2 font-medium sm:table-cell">Bottleneck</th>
                        <th class="hidden px-4 py-2 text-right font-medium lg:table-cell">When</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($traces as $trace)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <td class="max-w-[11rem] px-4 py-2.5 sm:max-w-md">
                                <a href="{{ route('sherlock.traces.show', ['trace' => $trace->id]) }}" class="block truncate font-mono text-xs font-medium hover:underline" title="{{ $trace->name }}">{{ $trace->name }}</a>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $trace->type->label() }}@if ($trace->statusCode) · <span class="{{ Badge::tone($trace->statusCode >= 500) }}">{{ $trace->statusCode }}</span>@endif · {{ $trace->recordReason->value }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ Format::ms($trace->durationMs) }}</td>
                            <td class="hidden px-4 py-2.5 text-right tabular-nums md:table-cell">{{ Format::number($trace->dbCount) }} <span class="text-xs text-zinc-500 dark:text-zinc-400">({{ Format::ms($trace->dbMs) }})</span></td>
                            <td class="hidden px-4 py-2.5 sm:table-cell">
                                <span class="flex items-center gap-1.5 text-xs"><span class="{{ Badge::bottleneck($trace->bottleneck) }} inline-block h-2 w-2 rounded-full"></span>{{ $trace->bottleneck->label() }}</span>
                            </td>
                            <td class="hidden px-4 py-2.5 text-right text-zinc-500 lg:table-cell dark:text-zinc-400" title="{{ Format::date($trace->createdAt) }}">{{ Format::ago($trace->createdAt) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @include('sherlock::dashboard.partials.pagination', ['paginator' => $traces])
@endsection
