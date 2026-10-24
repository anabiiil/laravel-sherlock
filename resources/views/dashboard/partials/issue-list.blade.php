{{-- $issues: list<IssueRecord>, $empty: message, optional $release: show first-seen release --}}
@use('Anabil\Sherlock\Http\Support\Format')
@if ($issues === [])
    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $empty }}</p>
@else
    <ul class="divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
        @foreach ($issues as $issue)
            <li class="flex items-center justify-between gap-3 py-2">
                <span class="min-w-0">
                    <a href="{{ route('sherlock.issues.show', ['issue' => $issue->id]) }}" class="block truncate hover:underline" title="{{ $issue->title }}">{{ $issue->title }}</a>
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ $issue->type->label() }} · {{ Format::number($issue->occurrences) }}×@if ($issue->impactMs > 0) · {{ Format::human($issue->impactMs) }} impact @endif
                        @if (! empty($release) && $issue->firstSeenRelease) · first seen {{ $issue->firstSeenRelease }}@endif
                    </span>
                </span>
                <span class="flex shrink-0 items-center gap-2">
                    @include('sherlock::dashboard.partials.status', ['status' => $issue->status])
                    @include('sherlock::dashboard.partials.severity', ['severity' => $issue->severity])
                </span>
            </li>
        @endforeach
    </ul>
@endif
