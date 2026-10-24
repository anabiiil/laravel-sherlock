<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <title>@hasSection('title')@yield('title') · @endif Sherlock</title>
    {{-- Pinned CDN builds with SRI; no npm / build step. --}}
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4.1.18/dist/index.global.js" integrity="sha384-y0axVOJIfo0bJOmTOnyRyUjrqjUotl7L/k6mzSwu2SSrB3r7P+b0cSFkiod+js0X" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js" integrity="sha384-X9kJyAubVxnP0hcA+AMMs21U445qsnqhnUF8EBlEpP3a42Kh/JwWjlv2ZcvGfphb" crossorigin="anonymous" defer></script>
    <style>
        [x-cloak] { display: none !important; }
        .tr-md { line-height: 1.6; overflow-wrap: anywhere; }
        .tr-md > * + * { margin-top: .75rem; }
        .tr-md h1, .tr-md h2, .tr-md h3, .tr-md h4 { font-weight: 600; margin-top: 1.25rem; }
        .tr-md h1 { font-size: 1.25rem; } .tr-md h2 { font-size: 1.125rem; } .tr-md h3, .tr-md h4 { font-size: 1rem; }
        .tr-md ul { list-style: disc; padding-left: 1.25rem; } .tr-md ol { list-style: decimal; padding-left: 1.25rem; }
        .tr-md a { text-decoration: underline; }
        .tr-md code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .85em; padding: .1rem .3rem; border-radius: .25rem; background: rgb(127 127 127 / .15); }
        .tr-md pre { overflow-x: auto; padding: .75rem; border-radius: .5rem; background: rgb(127 127 127 / .12); }
        .tr-md pre code { padding: 0; background: none; }
        .tr-md table { width: 100%; font-size: .875rem; } .tr-md th, .tr-md td { padding: .25rem .5rem; border-bottom: 1px solid rgb(127 127 127 / .25); text-align: left; }
    </style>
</head>
<body class="min-h-full bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
@php
    $nav = [
        'sherlock.overview' => ['Overview', 'sherlock.overview'],
        'sherlock.issues.index' => ['Issues', 'sherlock.issues.*'],
        'sherlock.traces.index' => ['Traces', 'sherlock.traces.*'],
        'sherlock.queries.index' => ['Queries', 'sherlock.queries.*'],
        'sherlock.deploys.index' => ['Deploys', 'sherlock.deploys.*'],
    ];
@endphp
<header class="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900" x-data="{ open: false }">
    <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
        <a href="{{ route('sherlock.overview') }}" class="flex items-center gap-2 font-semibold tracking-tight">
            <span class="inline-block h-2.5 w-2.5 rounded-full bg-sky-500"></span>
            Sherlock
        </a>
        <nav class="hidden gap-1 sm:flex" aria-label="Main">
            @foreach ($nav as $routeName => [$label, $pattern])
                <a href="{{ route($routeName) }}"
                   @class([
                       'rounded-md px-3 py-1.5 text-sm font-medium',
                       'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white' => request()->routeIs($pattern),
                       'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white' => ! request()->routeIs($pattern),
                   ])
                   @if (request()->routeIs($pattern)) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        <button type="button" class="rounded-md p-2 text-zinc-600 sm:hidden dark:text-zinc-300" x-on:click="open = ! open" aria-label="Toggle navigation">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
    </div>
    <nav class="border-t border-zinc-200 px-4 py-2 sm:hidden dark:border-zinc-800" x-show="open" x-cloak aria-label="Main mobile">
        @foreach ($nav as $routeName => [$label, $pattern])
            <a href="{{ route($routeName) }}" @class(['block rounded-md px-3 py-2 text-sm font-medium', 'bg-zinc-100 dark:bg-zinc-800' => request()->routeIs($pattern)])>{{ $label }}</a>
        @endforeach
    </nav>
</header>

<main class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
    @if (session('sherlock.status'))
        <div class="mb-4 flex items-start justify-between gap-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200" role="status" x-data="{ show: true }" x-show="show">
            <span>{{ session('sherlock.status') }}</span>
            <button type="button" class="text-emerald-700 dark:text-emerald-300" x-on:click="show = false" aria-label="Dismiss">&times;</button>
        </div>
    @endif

    @yield('content')
</main>
</body>
</html>
