{{-- $signals: TracePresenter::signalRows() --}}
@use('Anabil\Sherlock\Http\Support\Format')
@if ($signals['rows'] === [])
    <p class="text-sm text-zinc-500 dark:text-zinc-400">No server signals recorded (sherlock.server.* disabled, or recorded before they existed).</p>
@else
    @if ($signals['cpu_percent'] !== null)
        <div class="mb-1 flex h-2 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800" role="img" aria-label="CPU time vs wall time">
            <div class="h-full bg-emerald-500" style="width: {{ $signals['cpu_percent'] }}%" title="CPU time: {{ Format::percent($signals['cpu_percent']) }} of wall time"></div>
        </div>
        <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">
            CPU {{ Format::percent($signals['cpu_percent']) }} of wall time
            @if ($signals['waiting'])
                · mostly <span class="font-medium text-amber-700 dark:text-amber-400">waiting</span> (locks, sleep, I/O, a busy server)
            @endif
        </p>
    @endif
    <dl class="space-y-1 text-sm">
        @foreach ($signals['rows'] as $label => $value)
            <div class="flex justify-between gap-3">
                <dt class="shrink-0 text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                <dd @class(['min-w-0 text-right tabular-nums', 'font-medium text-red-700 dark:text-red-400' => $label === 'Load per core' && $signals['load_percent'] !== null && $signals['load_percent'] >= 150])>{{ $value }}</dd>
            </div>
        @endforeach
    </dl>
@endif
