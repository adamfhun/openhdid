<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? $appName }}</title>
</head>
<body style="margin:0;padding:0;background:{{ $palette['background'] }};font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:{{ $palette['text'] }};">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:{{ $palette['background'] }};padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;background:{{ $palette['surface'] }};border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08);">
                    <tr>
                        <td style="background:{{ $palette['primary'] }};padding:20px 32px;color:#ffffff;font-size:18px;font-weight:bold;">
                            @if ($logoUrl)
                                <img src="{{ $logoUrl }}" alt="{{ $appName }}" style="max-height:40px;vertical-align:middle;">
                            @else
                                {{ $appName }}
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;font-size:16px;line-height:1.6;">
                            <style>
                                .button { display:inline-block;background:{{ $palette['primary'] }};color:#ffffff !important;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:bold; }
                                h2 { margin:0 0 16px;font-size:22px; }
                                p { margin:0 0 16px; }
                                a { color:{{ $palette['primary'] }}; }
                            </style>
                            {!! $body !!}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 32px;background:{{ $palette['background'] }};font-size:12px;color:{{ $palette['muted'] }};">
                            © {{ date('Y') }} {{ $legalName }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
