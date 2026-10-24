{{-- $severity: Anabil\Tracing\Enums\Severity --}}
<span class="{{ \Anabil\Tracing\Http\Support\Badge::severity($severity) }} inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset">{{ $severity->label() }}</span>
