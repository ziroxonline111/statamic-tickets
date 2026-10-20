@php
    use KareliaLabs\Tickets\Order;
    $title = $order->isPaid() ? __('Your tickets') : __('Your order');
    $count = count($tickets);
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
@if ($refresh)<meta http-equiv="refresh" content="4">@endif
<title>{{ $title }} · {{ $order->eventTitle() }}</title>
<style>
:root{--ink:#111827;--muted:#4b5563;--line:#e5e7eb;--bg:#f3f4f6;--card:#fff;--accent:#2563eb;--ok:#047857;--warn:#b45309;--bad:#b91c1c;color-scheme:light}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;-webkit-font-smoothing:antialiased}
main{max-width:46rem;margin:0 auto;padding:2rem 1rem 3rem}
header{margin-bottom:1.5rem}
h1{font-size:1.75rem;line-height:1.2;margin:.25rem 0}
.eyebrow{color:var(--muted);font-size:.9rem;margin:0;text-transform:uppercase;letter-spacing:.06em;font-weight:600}
.when{color:var(--muted);margin:.25rem 0 0}
.status{background:var(--card);border:1px solid var(--line);border-radius:1rem;padding:1.25rem 1.25rem;margin-bottom:1.25rem}
.status h2{margin:0 0 .25rem;font-size:1.15rem}
.status p{margin:0;color:var(--muted)}
.status.ok{border-color:#a7f3d0;background:#ecfdf5}
.status.ok h2{color:var(--ok)}
.status.wait h2{color:var(--warn)}
.status.bad h2{color:var(--bad)}
.spinner{display:inline-block;width:1rem;height:1rem;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:spin .8s linear infinite;vertical-align:-2px;margin-right:.4rem}
@keyframes spin{to{transform:rotate(360deg)}}
.actions{display:flex;flex-wrap:wrap;gap:.6rem;margin:0 0 1.25rem}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.65rem 1rem;border-radius:.6rem;border:1px solid var(--line);background:var(--card);color:var(--ink);text-decoration:none;font:inherit;font-weight:600;cursor:pointer;min-height:2.75rem}
.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}
.tickets{display:grid;gap:1rem}
.ticket{background:var(--card);border:1px solid var(--line);border-radius:1rem;display:grid;grid-template-columns:auto 1fr;overflow:hidden;break-inside:avoid;page-break-inside:avoid}
.qr{background:#fff;padding:1rem;border-right:2px dashed var(--line);display:flex;align-items:center}
.qr svg{width:10.5rem;height:10.5rem;display:block}
.info{padding:1.1rem 1.25rem;display:grid;align-content:center;gap:.2rem}
.info .type{font-weight:700;font-size:1.15rem}
.info .who{color:var(--muted)}
.code{font:700 1.6rem/1.2 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;letter-spacing:.12em;margin-top:.35rem}
.code small{display:block;font:600 .75rem/1.2 system-ui,sans-serif;letter-spacing:.06em;color:var(--muted);text-transform:uppercase}
.of{color:var(--muted);font-size:.85rem}
.void{opacity:.55}
.void .code{text-decoration:line-through}
.note{background:var(--card);border:1px solid var(--line);border-radius:1rem;padding:1rem 1.25rem;margin-top:1.25rem;white-space:pre-line}
.meta{margin-top:1.25rem;color:var(--muted);font-size:.9rem;display:flex;flex-wrap:wrap;gap:.35rem 1.25rem}
.meta b{color:var(--ink);font-weight:600}
@media (max-width:30rem){.ticket{grid-template-columns:1fr}.qr{border-right:0;border-bottom:2px dashed var(--line);justify-content:center}.qr svg{width:13rem;height:13rem}.info{text-align:center}}
@media print{body{background:#fff}main{padding:0;max-width:none}.actions,.status.ok{display:none}.ticket{border-color:#999;margin-bottom:1rem}.note{border-color:#999}}
</style>
</head>
<body>
<main>
    <header>
        <p class="eyebrow">{{ $title }}</p>
        <h1>{{ $order->eventTitle() }}</h1>
        @if ($order->get('event.date_label'))
            <p class="when">{{ $order->get('event.date_label') }}</p>
        @endif
    </header>

    @if ($order->isPaid())
        <section class="status ok" role="status">
            <h2>{{ trans_choice('You are going! Here is your ticket.|You are going! Here are your :count tickets.', $count, ['count' => $count]) }}</h2>
            <p>{{ __('We also sent them to :email. Show the QR code at the door, on your phone or printed.', ['email' => $order->get('buyer.email')]) }}</p>
        </section>

        <div class="actions">
            <button class="btn primary" type="button" onclick="window.print()">{{ __('Print tickets') }}</button>
            @if ($calendarUrl)
                <a class="btn" href="{{ $calendarUrl }}">{{ __('Add to calendar') }}</a>
            @endif
            @if ($order->get('event.url'))
                <a class="btn" href="{{ $order->get('event.url') }}">{{ __('Event page') }}</a>
            @endif
        </div>
    @elseif ($order->isAwaitingPayment())
        <section class="status wait" role="status">
            <h2>{{ __('Your payment is on its way') }}</h2>
            <p>{{ __('Your bank is still sending the payment. We will email your tickets to :email as soon as it arrives. Your tickets are held for you until then.', ['email' => $order->get('buyer.email')]) }}</p>
        </section>
    @elseif ($order->isPending() && $refresh)
        <section class="status wait" role="status" aria-live="polite">
            <h2><span class="spinner" aria-hidden="true"></span>{{ __('Confirming your payment…') }}</h2>
            <p>{{ __('This usually takes a few seconds. This page updates by itself.') }}</p>
        </section>
    @elseif ($order->status() === Order::REFUNDED)
        <section class="status bad" role="status">
            <h2>{{ __('This order was refunded') }}</h2>
            <p>{{ __('The tickets below no longer work.') }}</p>
        </section>
    @else
        <section class="status bad" role="status">
            <h2>{{ __('This order was not completed') }}</h2>
            <p>{{ __('No payment was taken and no tickets were issued.') }}
                @if ($order->get('event.url'))
                    <a href="{{ $order->get('event.url') }}">{{ __('Back to the event') }}</a>
                @endif
            </p>
        </section>
    @endif

    @if ($count)
        <div class="tickets">
            @foreach ($tickets as $i => $ticket)
                <article class="ticket {{ $ticket['status'] === 'valid' && $order->isPaid() ? '' : 'void' }}">
                    @if ($ticket['qr'])
                        <div class="qr" role="img" aria-label="{{ __('QR code for ticket :code', ['code' => $ticket['code']]) }}">{!! $ticket['qr'] !!}</div>
                    @else
                        <div class="qr" aria-hidden="true"></div>
                    @endif
                    <div class="info">
                        <span class="type">{{ $ticket['type_name'] }}</span>
                        <span class="who">{{ $ticket['name'] }}</span>
                        <div class="code"><small>{{ __('Ticket code') }}</small>{{ substr($ticket['code'], 0, 4) }}&#8239;{{ substr($ticket['code'], 4) }}</div>
                        @if ($count > 1)<span class="of">{{ __('Ticket :n of :count', ['n' => $i + 1, 'count' => $count]) }}</span>@endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    @if ($order->isPaid() && $order->get('event.note'))
        <div class="note">{{ $order->get('event.note') }}</div>
    @endif

    <p class="meta">
        <span>{{ __('Order') }} <b>#{{ $order->number() }}</b></span>
        <span>{{ __('Total') }} <b>{{ $order->formattedTotal() }}</b></span>
        <span>{{ $order->get('buyer.name') }}</span>
    </p>
</main>
</body>
</html>
