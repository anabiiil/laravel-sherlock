@extends('sherlock::dashboard.layout')

@use('Anabil\Sherlock\Http\Support\Format')

@section('title', 'Issues')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold tracking-tight">Issues</h1>
    </div>

    <form method="GET" action="{{ route('sherlock.issues.index') }}" @class(['mb-4 grid grid-cols-2 gap-2 rounded-xl border border-zinc-200 bg-white p-3 text-sm sm:grid-cols-3 dark:border-zinc-800 dark:bg-zinc-900', 'lg:grid-cols-6' => $releases === [], 'lg:grid-cols-7' => $releases !== []])>
        <label class="col-span-2 sm:col-span-3 lg:col-span-2">
            <span class="sr-only">Search</span>
            <input type="search" name="search" value="{{ $filters->search }}" placeholder="Search title or fingerprint" maxlength="200"
                   class="w-full rounded-md border border-zinc-300 bg-white px-3 py-1.5 dark:border-zinc-700 dark:bg-zinc-950">
        </label>
        <label>
            <span class="sr-only">Type</span>
            <select name="type" class="w-full rounded-md border border-zinc-300 bg-white px-2 py-1.5 dark:border-zinc-700 dark:bg-zinc-950">
                <option value="">All types</option>
                @foreach ($types as $type)
                    <option value="{{ $type->value }}" @selected($filters->type === $type)>{{ $type->label() }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span class="sr-only">Severity</span>
            <select name="severity" class="w-full rounded-md border border-zinc-300 bg-white px-2 py-1.5 dark:border-zinc-700 dark:bg-zinc-950">
                <option value="">All severities</option>
                @foreach ($severities as $severity)
                    <option value="{{ $severity->value }}" @selected($filters->severity === $severity)>{{ $severity->label() }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span class="sr-only">Status</span>
            <select name="status" class="w-full rounded-md border border-zinc-300 bg-white px-2 py-1.5 dark:border-zinc-700 dark:bg-zinc-950">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected($filters->status === $status)>{{ $status->label() }}</option>
                @endforeach
                <option value="all" @selected($filters->status === null)>All statuses</option>
            </select>
        </label>
        @if ($releases !== [])
            <label>
                <span class="sr-only">First seen in release</span>
                <select name="release" class="w-full rounded-md border border-zinc-300 bg-white px-2 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-950" title="Only issues first seen in this release">
                    <option value="">Any release</option>
                    @foreach ($releases as $release)
                        <option value="{{ $release->release }}" @selected($filters->release === $release->release)>{{ $release->release }}</option>
                    @endforeach
                    @if ($filters->release !== null && ! collect($releases)->contains('release', $filters->release))
                        <option value="{{ $filters->release }}" selected>{{ $filters->release }}</option>
                    @endif
                </select>
            </label>
        @endif
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

    @if ($issues->total() === 0)
        @include('sherlock::dashboard.partials.empty', [
            'message' => 'No issues match these filters.',
            'hint' => 'Issues appear when a recorded trace crosses a threshold (slow query, N+1, exception...).',
        ])
    @else
        <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                <thead class="bg-zinc-50 text-left text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-2 font-medium">Issue</th>
                        <th class="px-4 py-2 font-medium">Severity</th>
                        <th class="hidden px-4 py-2 font-medium md:table-cell">Status</th>
                        <th class="px-4 py-2 text-right font-medium">Occurrences</th>
                        <th class="hidden px-4 py-2 text-right font-medium sm:table-cell" title="Cumulative time cost of every occurrence">Impact</th>
                        <th class="hidden px-4 py-2 text-right font-medium sm:table-cell">Last seen</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($issues as $issue)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <td class="max-w-[11rem] px-4 py-2.5 sm:max-w-md">
                                <a href="{{ route('sherlock.issues.show', ['issue' => $issue->id]) }}" class="block truncate font-medium hover:underline" title="{{ $issue->title }}">{{ $issue->title }}</a>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $issue->type->label() }}@if ($issue->regressedAt) · regressed {{ Format::ago($issue->regressedAt) }}@endif @if ($issue->firstSeenRelease) · since <span class="font-mono">{{ $issue->firstSeenRelease }}</span>@endif</span>
                            </td>
                            <td class="px-4 py-2.5">@include('sherlock::dashboard.partials.severity', ['severity' => $issue->severity])</td>
                            <td class="hidden px-4 py-2.5 md:table-cell">@include('sherlock::dashboard.partials.status', ['status' => $issue->status])</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ Format::number($issue->occurrences) }}</td>
                            <td class="hidden px-4 py-2.5 text-right tabular-nums sm:table-cell">{{ $issue->impactMs > 0 ? Format::human($issue->impactMs) : '-' }}</td>
                            <td class="hidden px-4 py-2.5 text-right text-zinc-500 sm:table-cell dark:text-zinc-400" title="{{ Format::date($issue->lastSeenAt) }}">{{ Format::ago($issue->lastSeenAt) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @include('sherlock::dashboard.partials.pagination', ['paginator' => $issues])
@endsection
