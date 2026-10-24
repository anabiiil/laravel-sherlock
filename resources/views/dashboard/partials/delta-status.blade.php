{{-- $status: EntryDelta::SLOWER | FASTER | UNCHANGED | NEW | GONE --}}
<span class="{{ \Anabil\Sherlock\Http\Support\Badge::delta($status) }} inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset">{{ ucfirst($status) }}</span>
