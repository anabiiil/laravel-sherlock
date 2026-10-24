{{-- $message, optional $hint --}}
<div class="rounded-lg border border-dashed border-zinc-300 px-4 py-8 text-center dark:border-zinc-700">
    <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ $message }}</p>
    @isset($hint)
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $hint }}</p>
    @endisset
</div>
