{{-- $leak: TracePresenter::memoryLeakRows() --}}
<p class="mb-3 text-sm text-zinc-600 dark:text-zinc-300">The queue worker's memory kept growing across consecutive jobs. Restart workers regularly (--max-jobs / --max-time) and look for static caches, event listeners or singletons that accumulate state.</p>
<dl class="space-y-1 text-sm">
    @foreach ($leak['rows'] as $label => $value)
        <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt><dd class="tabular-nums">{{ $value }}</dd></div>
    @endforeach
</dl>
@if ($leak['contributors'] !== [])
    <p class="mb-1 mt-4 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Growth by job class (recent jobs)</p>
    <ul class="space-y-1 text-sm">
        @foreach ($leak['contributors'] as $class => $bytes)
            <li class="flex justify-between gap-3"><span class="min-w-0 truncate font-mono text-xs" title="{{ $class }}">{{ $class }}</span><span class="shrink-0 tabular-nums">{{ $bytes }}</span></li>
        @endforeach
    </ul>
@endif
