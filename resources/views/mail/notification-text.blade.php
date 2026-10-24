{{-- Plain-text part: not HTML, so values are printed unescaped. --}}
{!! $emoji !!} [{!! $severity !!}] {!! $title !!}

{!! $summary !!}

@foreach ($fields as $label => $value)
{!! $label !!}: {!! $value !!}
@endforeach
@if ($sql)

Query:
{!! $sql !!}
@endif
@if ($ai)

AI recommendation:
{!! $ai !!}
@endif
@if ($occurrences > 1)

Occurrences in this message: {!! $occurrences !!}
@endif
@if ($url)

Open in dashboard: {!! $url !!}
@endif
@if ($footer)

--
{!! $footer !!}
@endif
