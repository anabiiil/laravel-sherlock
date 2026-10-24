{{-- $paginator: LengthAwarePaginator (links already carry the filters) --}}
@if ($paginator->total() > 0)
    <nav class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm" aria-label="Pagination">
        <p class="text-zinc-500 dark:text-zinc-400">
            Showing {{ number_format((int) $paginator->firstItem()) }}–{{ number_format((int) $paginator->lastItem()) }} of {{ number_format($paginator->total()) }}
        </p>
        @if ($paginator->hasPages())
            <div class="flex items-center gap-2">
                @if ($paginator->onFirstPage())
                    <span class="rounded-md border border-zinc-200 px-3 py-1.5 text-zinc-400 dark:border-zinc-800 dark:text-zinc-600">Previous</span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="rounded-md border border-zinc-300 bg-white px-3 py-1.5 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800">Previous</a>
                @endif
                <span class="text-zinc-500 dark:text-zinc-400">Page {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="rounded-md border border-zinc-300 bg-white px-3 py-1.5 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800">Next</a>
                @else
                    <span class="rounded-md border border-zinc-200 px-3 py-1.5 text-zinc-400 dark:border-zinc-800 dark:text-zinc-600">Next</span>
                @endif
            </div>
        @endif
    </nav>
@endif
