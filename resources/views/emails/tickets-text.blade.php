{!! __('Your tickets') !!}: {!! $order->eventTitle() !!}
@if ($order->get('event.date_label'))
{!! $order->get('event.date_label') !!}
@endif

{!! __('Hi :name, thank you! Show the QR code from your ticket page at the door, on your phone or printed.', ['name' => $order->get('buyer.name')]) !!}

{!! __('Open your tickets') !!}: {!! $ticketsUrl !!}

@foreach ($order->tickets() as $ticket)
- {!! $ticket['type_name'] !!}: {!! $ticket['code'] !!}
@endforeach
@if ($order->get('event.note'))

{!! $order->get('event.note') !!}
@endif

{!! __('Order') !!} #{!! $order->number() !!} · {!! __('Total') !!} {!! $order->formattedTotal() !!}
@if ($eventUrl)
{!! $eventUrl !!}
@endif
