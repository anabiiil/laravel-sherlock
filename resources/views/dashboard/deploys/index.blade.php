@extends('sherlock::dashboard.layout')

@use('Anabil\Sherlock\Data\EntryDelta')
@use('Anabil\Sherlock\Digest\DeploySummary')
@use('Anabil\Sherlock\Http\Support\Format')

@section('title', 'Deploys')

@php
    $card = 'rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900';
    $select = 'w-full rounded-md border border-zinc-300 bg-white px-2 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-950';
    $biasNote = 'Biased: based on every stored trace of this entry. In production only slow (threshold) and sampled traces are stored, so this average leans towards slow executions. '
        .'It switches to sampled traces only once both releases have at least '.$minSamples.' of them (releases.compare_min_samples); raise sample_rate for fair comparisons.';
    $biased = $comparison !== null && collect($comparison->entries)->contains(fn ($d) => $d->basis === EntryDelta::BASIS_ALL && $d->current !== null && $d->previous !== null);
    $counts = $comparison === null ? [] : collect($comparison->entries)->countBy(fn ($d) => $d->status)->all();
    $deploy = fn (?\Anabil\Sherlock\Data\ReleaseRecord $r) => $r === null ? '' : 'first seen '.Format::ago($r->firstSeenAt);
@endphp

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Deploys</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Before / after comparison of two releases: routes, jobs and commands that got slower or faster, and the issues a deploy introduced.</p>
        </div>
        @if ($appRelease)
            <p class="text-xs text-zinc-500 dark:text-zinc-400">This process runs release <span class="font-mono font-medium text-zinc-800 dark:text-zinc-200">{{ $appRelease }}</span></p>
        @endif
    </div>

    @if (count($releases) < 2)
        @include('sherlock::dashboard.partials.empty', [
            'message' => count($releases) === 0 ? 'No releases recorded yet.' : 'Only one release recorded so far: nothing to compare yet.',
            'hint' => 'Each trace is stamped with the current release: SHERLOCK_RELEASE when set (e.g. your CI commit SHA), otherwise a .release / REVISION file or the git HEAD of the app (short SHA). '
                .'Run "php artisan config:cache" on every deploy so the value is detected once and baked into the cached config. The comparison appears after the next deploy.',
        ])
    @else
        <form method="GET" action="{{ route('sherlock.deploys.index') }}" class="mb-4 grid grid-cols-2 gap-2 rounded-xl border border-zinc-200 bg-white p-3 text-sm sm:grid-cols-4 dark:border-zinc-800 dark:bg-zinc-900">
            <label>
                <span class="mb-1 block text-xs text-zinc-500 dark:text-zinc-400">Release (after)</span>
                <select name="current" class="{{ $select }}">
                    @foreach ($releases as $releaseOption)
                        <option value="{{ $releaseOption->release }}" @selected($current === $releaseOption->release)>{{ $releaseOption->release }} · {{ Format::ago($releaseOption->firstSeenAt) }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span class="mb-1 block text-xs text-zinc-500 dark:text-zinc-400">Compared with (before)</span>
                <select name="previous" class="{{ $select }}">
                    @foreach ($releases as $releaseOption)
                        <option value="{{ $releaseOption->release }}" @selected($previous === $releaseOption->release)>{{ $releaseOption->release }} · {{ Format::ago($releaseOption->firstSeenAt) }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span class="mb-1 block text-xs text-zinc-500 dark:text-zinc-400">Entries</span>
                <select name="type" class="w-full rounded-md border border-zinc-300 bg-white px-2 py-1.5 dark:border-zinc-700 dark:bg-zinc-950">
                    <option value="">All types</option>
                    @foreach ($types as $entryType)
                        <option value="{{ $entryType->value }}" @selected($type === $entryType)>{{ $entryType->label() }}s</option>
                    @endforeach
                </select>
            </label>
            <div class="flex items-end">
                <button type="submit" class="w-full rounded-md bg-zinc-900 px-3 py-1.5 font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">Compare</button>
            </div>
        </form>

        @if ($same)
            @include('sherlock::dashboard.partials.empty', ['message' => 'Pick two different releases to compare.'])
        @elseif ($comparison === null)
            @include('sherlock::dashboard.partials.empty', [
                'message' => 'No release recorded before '.$current.'.',
                'hint' => 'Pick an older release in "Compared with".',
            ])
        @else
            {{-- Headline --}}
            <section @class([$card, 'border-l-4', 'border-l-red-500' => $comparison->hasRegressions(), 'border-l-emerald-500' => ! $comparison->hasRegressions()])>
                <p class="text-lg font-semibold tracking-tight">{{ DeploySummary::headline($comparison) }}</p>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    <span class="font-mono text-zinc-800 dark:text-zinc-200">{{ $comparison->current }}</span> {{ $deploy($comparison->currentRelease) ? '('.$deploy($comparison->currentRelease).')' : '' }}
                    compared with
                    <span class="font-mono text-zinc-800 dark:text-zinc-200">{{ $comparison->previous }}</span> {{ $deploy($comparison->previousRelease) ? '('.$deploy($comparison->previousRelease).')' : '' }}
                    @if ($type) · {{ $type->label() }}s only @endif
                </p>
                <ul class="mt-3 flex flex-wrap gap-2 text-xs">
                    @foreach ([EntryDelta::SLOWER, EntryDelta::NEW, EntryDelta::FASTER, EntryDelta::UNCHANGED, EntryDelta::GONE] as $status)
                        <li class="flex items-center gap-1.5">@include('sherlock::dashboard.partials.delta-status', ['status' => $status]) <span class="tabular-nums">{{ $counts[$status] ?? 0 }}</span></li>
                    @endforeach
                    <li class="ml-2 text-zinc-500 dark:text-zinc-400">{{ count($comparison->newIssues) }} new · {{ count($comparison->regressedIssues) }} regressed · {{ count($comparison->goneIssues) }} gone issues</li>
                </ul>
            </section>

            @if ($biased)
                <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                    Entries marked <span class="font-semibold">biased</span> are averaged over every stored trace, not only sampled ones{{ $mode === 'local' ? '' : ' (production mode stores slow traces and a '.Format::percent($sampleRate * 100).' sample)' }}.
                    Treat their deltas as indicative; a higher <span class="font-mono">sample_rate</span> gives fair before / after numbers.
                </p>
            @endif

            {{-- Entries --}}
            <section class="{{ $card }} mt-6">
                @include('sherlock::dashboard.partials.card-title', ['title' => 'Routes, jobs & commands'])
                @if ($comparison->entries === [])
                    @include('sherlock::dashboard.partials.empty', ['message' => 'No traces stored for these releases.'])
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-xs text-zinc-500 dark:text-zinc-400">
                                <tr>
                                    <th class="py-1 pr-3 font-medium">Entry</th>
                                    <th class="py-1 pr-3 font-medium">Status</th>
                                    <th class="py-1 pr-3 text-right font-medium">Avg before &rarr; after</th>
                                    <th class="py-1 pr-3 text-right font-medium">Change</th>
                                    <th class="hidden py-1 pr-3 text-right font-medium md:table-cell">DB time</th>
                                    <th class="hidden py-1 pr-3 text-right font-medium md:table-cell">Queries</th>
                                    <th class="hidden py-1 pr-3 text-right font-medium lg:table-cell">Traces</th>
                                    <th class="py-1 font-medium">Basis</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach ($comparison->entries as $delta)
                                    <tr class="align-top">
                                        <td class="max-w-xs py-2 pr-3">
                                            <a href="{{ route('sherlock.traces.index', ['type' => $delta->type->value, 'name' => $delta->name, 'release' => $delta->current ? $comparison->current : $comparison->previous]) }}" class="block truncate font-mono text-xs hover:underline" title="{{ $delta->name }}">{{ $delta->name }}</a>
                                            <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $delta->type->label() }}</span>
                                        </td>
                                        <td class="py-2 pr-3">@include('sherlock::dashboard.partials.delta-status', ['status' => $delta->status])</td>
                                        <td class="whitespace-nowrap py-2 pr-3 text-right tabular-nums">
                                            {{ $delta->previous ? Format::ms($delta->previous->avgMs) : '-' }} &rarr; {{ $delta->current ? Format::ms($delta->current->avgMs) : '-' }}
                                        </td>
                                        <td @class([
                                            'whitespace-nowrap py-2 pr-3 text-right tabular-nums',
                                            'text-red-700 dark:text-red-400' => $delta->status === EntryDelta::SLOWER,
                                            'text-emerald-700 dark:text-emerald-400' => $delta->status === EntryDelta::FASTER,
                                        ])>
                                            @if ($delta->deltaAvgMs === null)
                                                <span class="text-zinc-400 dark:text-zinc-600">-</span>
                                            @else
                                                {{ Format::signedRatio($delta->deltaRatio) }}
                                                <span class="block text-xs">{{ Format::signedMs($delta->deltaAvgMs) }}</span>
                                            @endif
                                        </td>
                                        <td class="hidden whitespace-nowrap py-2 pr-3 text-right tabular-nums md:table-cell">
                                            {{ Format::signedMs($delta->deltaAvgDbMs) }}
                                            @if ($delta->previous && $delta->current)
                                                <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ Format::ms($delta->previous->avgDbMs) }} &rarr; {{ Format::ms($delta->current->avgDbMs) }}</span>
                                            @endif
                                        </td>
                                        <td class="hidden whitespace-nowrap py-2 pr-3 text-right tabular-nums md:table-cell">
                                            {{ Format::signedNumber($delta->deltaAvgQueries) }}
                                            @if ($delta->previous && $delta->current)
                                                <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ Format::number($delta->previous->avgQueries) }} &rarr; {{ Format::number($delta->current->avgQueries) }}</span>
                                            @endif
                                        </td>
                                        <td class="hidden whitespace-nowrap py-2 pr-3 text-right tabular-nums text-zinc-500 lg:table-cell dark:text-zinc-400">
                                            {{ $delta->previous ? Format::number($delta->previous->count) : 0 }} &rarr; {{ $delta->current ? Format::number($delta->current->count) : 0 }}
                                        </td>
                                        <td class="py-2">
                                            @if ($delta->current === null || $delta->previous === null)
                                                <span class="text-xs text-zinc-400 dark:text-zinc-600">-</span>
                                            @elseif ($delta->basis === EntryDelta::BASIS_ALL)
                                                <span class="cursor-help rounded-md bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-500/15 dark:text-amber-300" title="{{ $biasNote }}">biased</span>
                                            @else
                                                <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300" title="Averaged over sampled traces only (record_reason = sample): unbiased.">sampled</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            {{-- Issues --}}
            <div class="mt-6 grid gap-6 lg:grid-cols-3">
                <section class="{{ $card }} min-w-0">
                    @include('sherlock::dashboard.partials.card-title', ['title' => 'New in '.$comparison->current])
                    @include('sherlock::dashboard.partials.issue-list', ['issues' => $comparison->newIssues, 'empty' => 'No new issues in this release.', 'release' => false])
                </section>
                <section class="{{ $card }} min-w-0">
                    @include('sherlock::dashboard.partials.card-title', ['title' => 'Regressed in '.$comparison->current])
                    @include('sherlock::dashboard.partials.issue-list', ['issues' => $comparison->regressedIssues, 'empty' => 'No resolved issue came back.', 'release' => true])
                </section>
                <section class="{{ $card }} min-w-0">
                    @include('sherlock::dashboard.partials.card-title', ['title' => 'Gone since '.$comparison->previous])
                    @include('sherlock::dashboard.partials.issue-list', ['issues' => $comparison->goneIssues, 'empty' => 'Every issue of the previous release still shows up.', 'release' => true])
                    @if ($comparison->goneIssues !== [])
                        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">Last seen in {{ $comparison->previous }}: maybe fixed by this deploy, or just not triggered yet.</p>
                    @endif
                </section>
            </div>
        @endif
    @endif

    {{-- Releases --}}
    @if ($releases !== [])
        <section class="{{ $card }} mt-6">
            @include('sherlock::dashboard.partials.card-title', ['title' => 'Releases'])
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs text-zinc-500 dark:text-zinc-400">
                        <tr><th class="py-1 pr-3 font-medium">Release</th><th class="py-1 pr-3 font-medium">First seen</th><th class="py-1 pr-3 font-medium">Last seen</th><th class="py-1 text-right font-medium"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($releases as $release)
                            <tr>
                                <td class="py-1.5 pr-3 font-mono text-xs">
                                    {{ $release->release }}
                                    @if ($release->release === $appRelease)
                                        <span class="ml-1 rounded bg-sky-100 px-1.5 py-0.5 font-sans font-medium text-sky-800 dark:bg-sky-500/15 dark:text-sky-300">running</span>
                                    @endif
                                </td>
                                <td class="py-1.5 pr-3 text-zinc-600 dark:text-zinc-400" title="{{ Format::date($release->firstSeenAt) }}">{{ Format::ago($release->firstSeenAt) }}</td>
                                <td class="py-1.5 pr-3 text-zinc-600 dark:text-zinc-400" title="{{ Format::date($release->lastSeenAt) }}">{{ Format::ago($release->lastSeenAt) }}</td>
                                <td class="whitespace-nowrap py-1.5 text-right text-xs">
                                    @if (! $loop->last)
                                        <a href="{{ route('sherlock.deploys.index', ['current' => $release->release, 'previous' => $releases[$loop->index + 1]->release]) }}" class="text-sky-700 hover:underline dark:text-sky-400">Compare with previous</a> ·
                                    @endif
                                    <a href="{{ route('sherlock.traces.index', ['release' => $release->release]) }}" class="text-sky-700 hover:underline dark:text-sky-400">Traces</a> ·
                                    <a href="{{ route('sherlock.issues.index', ['release' => $release->release, 'status' => 'all']) }}" class="text-sky-700 hover:underline dark:text-sky-400">Issues first seen</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection
