{{-- $period: Period, $route: route name, optional $params: extra query values --}}
<div class="inline-flex rounded-lg border border-zinc-200 bg-white p-0.5 text-sm dark:border-zinc-800 dark:bg-zinc-900" role="group" aria-label="Period">
    @foreach (\Anabil\Sherlock\Http\Support\Period::OPTIONS as $key => $label)
        <a href="{{ route($route, [...($params ?? []), 'period' => $key]) }}" title="{{ $label }}"
           @class([
               'rounded-md px-3 py-1 font-medium',
               'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $period->key === $key,
               'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' => $period->key !== $key,
           ])
           @if ($period->key === $key) aria-current="true" @endif>{{ $key }}</a>
    @endforeach
</div>
