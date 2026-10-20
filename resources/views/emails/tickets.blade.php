<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ __('Your tickets') }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#111827;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;">
<tr><td style="padding:28px 28px 8px;">
    <p style="margin:0;font-size:13px;letter-spacing:.06em;text-transform:uppercase;color:#4b5563;font-weight:600;">{{ __('Your tickets') }}</p>
    <h1 style="margin:6px 0 4px;font-size:24px;line-height:1.25;">{{ $order->eventTitle() }}</h1>
    @if ($order->get('event.date_label'))
        <p style="margin:0;color:#4b5563;font-size:15px;">{{ $order->get('event.date_label') }}</p>
    @endif
</td></tr>
<tr><td style="padding:16px 28px 4px;font-size:16px;line-height:1.5;">
    <p style="margin:0 0 16px;">{{ __('Hi :name, thank you! Show the QR code from your ticket page at the door, on your phone or printed.', ['name' => $order->get('buyer.name')]) }}</p>
    <p style="margin:0 0 20px;">
        <a href="{{ $ticketsUrl }}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;font-weight:600;padding:12px 20px;border-radius:8px;">{{ __('Open your tickets') }}</a>
    </p>
</td></tr>
<tr><td style="padding:0 28px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #e5e7eb;">
        @foreach ($order->tickets() as $ticket)
            <tr>
                <td style="padding:10px 0;border-bottom:1px solid #e5e7eb;font-size:15px;">{{ $ticket['type_name'] }}</td>
                <td align="right" style="padding:10px 0;border-bottom:1px solid #e5e7eb;font-family:Menlo,Consolas,monospace;font-size:16px;font-weight:700;letter-spacing:.1em;">{{ $ticket['code'] }}</td>
            </tr>
        @endforeach
    </table>
</td></tr>
@if ($order->get('event.note'))
<tr><td style="padding:16px 28px 0;font-size:15px;line-height:1.5;color:#374151;white-space:pre-line;">{{ $order->get('event.note') }}</td></tr>
@endif
<tr><td style="padding:20px 28px 28px;font-size:14px;color:#4b5563;line-height:1.6;">
    {{ __('Order') }} #{{ $order->number() }} · {{ __('Total') }} {{ $order->formattedTotal() }}<br>
    @if ($eventUrl)<a href="{{ $eventUrl }}" style="color:#2563eb;">{{ __('Event page') }}</a> · @endif
    {{ __('The event is attached as a calendar file.') }}
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
