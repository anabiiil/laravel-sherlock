{{-- $breakdown: Anabil\Sherlock\Http\Presenters\Breakdown --}}
@use('Anabil\Sherlock\Http\Support\Format')
@if ($breakdown->isEmpty())
    <p class="text-sm text-zinc-500 dark:text-zinc-400">No timing breakdown recorded.</p>
@else
    <div class="flex h-3 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800" role="img" aria-label="Time breakdown">
        @foreach ($breakdown->segments as $segment)
            <div class="{{ $segment['color'] }} h-full" style="width: {{ $segment['percent'] }}%" title="{{ $segment['label'] }}: {{ Format::ms($segment['ms']) }} ({{ Format::percent($segment['percent']) }})"></div>
        @endforeach
    </div>
    <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-600 dark:text-zinc-400">
        @foreach ($breakdown->segments as $segment)
            <li class="flex items-center gap-1.5">
                <span class="{{ $segment['color'] }} inline-block h-2 w-2 rounded-full"></span>
                {{ $segment['label'] }} <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ Format::ms($segment['ms']) }}</span> ({{ Format::percent($segment['percent']) }})
            </li>
        @endforeach
    </ul>
@endif
