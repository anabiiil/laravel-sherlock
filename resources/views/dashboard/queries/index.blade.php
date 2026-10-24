@extends('sherlock::dashboard.layout')

@use('Anabil\Sherlock\Http\Support\Format')

@section('title', 'Queries')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Queries</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Top fingerprints · {{ $period->label() }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <div class="inline-flex rounded-lg border border-zinc-200 bg-white p-0.5 text-sm dark:border-zinc-800 dark:bg-zinc-900" role="group" aria-label="Order">
                @foreach ($orders as $key => $label)
                    <a href="{{ route('sherlock.queries.index', ['period' => $period->key, 'order' => $key]) }}"
                       @class([
                           'rounded-md px-3 py-1 font-medium',
                           'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $order === $key,
                           'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' => $order !== $key,
                       ])>{{ $label }}</a>
                @endforeach
            </div>
            @include('sherlock::dashboard.partials.period', ['route' => 'sherlock.queries.index', 'params' => ['order' => $order]])
        </div>
    </div>

    @if ($rows === [])
        @include('sherlock::dashboard.partials.empty', [
            'message' => 'No queries recorded in this period.',
            'hint' => 'Query rows are stored with recorded traces only.',
        ])
    @else
        <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                <thead class="bg-zinc-50 text-left text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-2 font-medium">Query</th>
                        <th class="px-4 py-2 text-right font-medium">Executions</th>
                        <th class="px-4 py-2 text-right font-medium">Total</th>
                        <th class="hidden px-4 py-2 text-right font-medium sm:table-cell">Avg</th>
                        <th class="hidden px-4 py-2 text-right font-medium md:table-cell">Max</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($rows as $row)
                        <tr class="align-top">
                            <td class="px-4 py-2.5">
                                <code class="block max-w-2xl break-words font-mono text-xs">{{ $row['sql'] }}</code>
                                <span class="mt-1 flex flex-wrap items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                                    <span class="font-mono" title="{{ $row['stat']->fingerprint }}">{{ Format::short($row['stat']->fingerprint) }}</span>
                                    @if ($row['issue'])
                                        <a href="{{ route('sherlock.issues.show', ['issue' => $row['issue']->id]) }}" class="text-sky-700 hover:underline dark:text-sky-400">Missing index issue &rarr;</a>
                                    @endif
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ Format::number($row['stat']->count) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ Format::ms($row['stat']->totalMs) }}</td>
                            <td class="hidden px-4 py-2.5 text-right tabular-nums sm:table-cell">{{ Format::ms($row['stat']->avgMs) }}</td>
                            <td class="hidden px-4 py-2.5 text-right tabular-nums md:table-cell">{{ Format::ms($row['stat']->maxMs) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">Top {{ count($rows) }} fingerprints from stored query rows (stored traces only).</p>
    @endif
@endsection
