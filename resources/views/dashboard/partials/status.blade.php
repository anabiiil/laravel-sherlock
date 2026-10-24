{{-- $status: Anabil\Sherlock\Enums\IssueStatus --}}
<span class="{{ \Anabil\Sherlock\Http\Support\Badge::status($status) }} inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset">{{ $status->label() }}</span>
