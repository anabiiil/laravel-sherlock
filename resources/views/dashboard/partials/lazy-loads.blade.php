{{-- $lazyLoads: TracePresenter::lazyLoadRows() --}}
<ul class="divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
    @foreach ($lazyLoads as $lazy)
        <li class="flex items-center justify-between gap-3 py-1.5">
            <span class="min-w-0">
                <span class="block truncate font-mono text-xs font-medium" title="{{ $lazy['model'] }}::{{ $lazy['relation'] }}">{{ $lazy['short'] }}</span>
                <span class="text-xs text-zinc-500 dark:text-zinc-400">eager load with ->with('{{ $lazy['relation'] }}')@if ($lazy['table']) · table {{ $lazy['table'] }}@endif</span>
            </span>
            <span class="shrink-0 text-xs tabular-nums">{{ $lazy['count'] }}×</span>
        </li>
    @endforeach
</ul>
