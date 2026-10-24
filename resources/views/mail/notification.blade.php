{{-- Issue notification (HTML). Every value is escaped with {{ }}; never use {!! !!} here. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2328;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:8px;border-top:4px solid {{ $color }};">
    <tr>
        <td style="padding:24px;">
            <p style="margin:0 0 4px;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:{{ $color }};font-weight:600;">{{ $emoji }} {{ $severity }}</p>
            <h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;">{{ $title }}</h1>
            <p style="margin:0 0 20px;font-size:14px;line-height:1.5;">{{ $summary }}</p>

            @if (! empty($fields))
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;border-collapse:collapse;margin-bottom:20px;">
                    @foreach ($fields as $label => $value)
                        <tr>
                            <td style="padding:6px 12px 6px 0;color:#57606a;white-space:nowrap;vertical-align:top;border-bottom:1px solid #eaeef2;">{{ $label }}</td>
                            <td style="padding:6px 0;vertical-align:top;border-bottom:1px solid #eaeef2;word-break:break-word;">{{ $value }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif

            @if ($sql)
                <p style="margin:0 0 6px;font-size:13px;font-weight:600;">Query</p>
                <pre style="margin:0 0 20px;padding:12px;background:#f6f8fa;border-radius:6px;font-size:12px;line-height:1.45;white-space:pre-wrap;word-break:break-word;font-family:SFMono-Regular,Consolas,'Liberation Mono',Menlo,monospace;">{{ $sql }}</pre>
            @endif

            @if ($ai)
                <p style="margin:0 0 6px;font-size:13px;font-weight:600;">💡 AI recommendation</p>
                <div style="margin:0 0 20px;padding:12px;background:#f0f7ff;border-radius:6px;font-size:13px;line-height:1.5;white-space:pre-wrap;">{{ $ai }}</div>
            @endif

            @if ($occurrences > 1)
                <p style="margin:0 0 20px;font-size:13px;color:#57606a;">Occurrences in this message: <strong>{{ $occurrences }}</strong></p>
            @endif

            @if ($url)
                <p style="margin:0 0 8px;">
                    <a href="{{ $url }}" style="display:inline-block;padding:10px 16px;background:#1f2328;color:#ffffff;text-decoration:none;border-radius:6px;font-size:13px;">Open in dashboard</a>
                </p>
            @endif
        </td>
    </tr>
    @if ($footer)
        <tr>
            <td style="padding:12px 24px;border-top:1px solid #eaeef2;font-size:12px;color:#57606a;">{{ $footer }}</td>
        </tr>
    @endif
</table>
</body>
</html>
