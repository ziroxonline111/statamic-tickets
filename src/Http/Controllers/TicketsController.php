<?php

namespace KareliaLabs\Tickets\Http\Controllers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use KareliaLabs\Tickets\Event;
use KareliaLabs\Tickets\Order;
use KareliaLabs\Tickets\OrderRepository;
use KareliaLabs\Tickets\Report;
use KareliaLabs\Tickets\Settings;
use KareliaLabs\Tickets\Urls;
use KareliaLabs\Tickets\Http\Controllers\WebhookController;
use Statamic\Facades\Addon;
use Statamic\Facades\Collection;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketsController extends CpController
{
    public function index(Report $report)
    {
        $this->authorize('view tickets');

        $user = User::current();

        return Inertia::render('statamic-tickets::TicketsIndex', [
            'events' => $report->events()->map(fn (Event $e) => $report->summary($e) + [
                'show_url' => cp_route('karelia-tickets.show', $e->id()),
                'check_in_url' => cp_route('karelia-tickets.check-in', ['event' => $e->id()]),
            ])->all(),
            'recent' => $report->recent(30),
            'setup' => $this->setup(),
            'canCheckIn' => $user->isSuper() || $user->hasPermission('check in attendees'),
            'checkInUrl' => cp_route('karelia-tickets.check-in'),
            'settingsUrl' => $user->isSuper() || $user->hasPermission('configure addons')
                ? Addon::get(Settings::PACKAGE)?->settingsUrl()
                : null,
        ]);
    }

    public function show(string $event, Report $report, OrderRepository $orders)
    {
        $this->authorize('view tickets');

        $event = Event::find($event) ?? abort(404);
        $user = User::current();
        $list = $orders->forEvent($event->id());

        return Inertia::render('statamic-tickets::TicketsEvent', [
            'event' => $report->summary($event),
            'types' => $report->types($event),
            'orders' => $list->map(fn (Order $o) => $this->presentOrder($o))->all(),
            'attendees' => $list->filter(fn (Order $o) => $o->isPaid())
                ->flatMap(fn (Order $o) => collect($o->tickets())->map(fn ($t) => [
                    'code' => $t['code'],
                    'type' => $t['type_name'],
                    'name' => $t['name'],
                    'email' => $o->get('buyer.email'),
                    'order' => $o->number(),
                    'checked_in_at' => $t['checked_in_at'] ? $this->time($t['checked_in_at']) : null,
                ]))
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all(),
            'canManage' => $user->isSuper() || $user->hasPermission('manage ticket orders'),
            'canCheckIn' => $user->isSuper() || $user->hasPermission('check in attendees'),
            'urls' => [
                'index' => cp_route('karelia-tickets.index'),
                'export' => cp_route('karelia-tickets.export', $event->id()),
                'complimentary' => cp_route('karelia-tickets.complimentary', $event->id()),
                'checkIn' => cp_route('karelia-tickets.check-in', ['event' => $event->id()]),
            ],
            'stripeReady' => Settings::stripeSecret() !== null,
        ]);
    }

    /** Attendee list as CSV, opens in Excel, Numbers and Google Sheets. */
    public function export(string $event, OrderRepository $orders): StreamedResponse
    {
        $this->authorize('view tickets');

        $event = Event::find($event) ?? abort(404);
        $rows = $orders->forEvent($event->id())->filter(fn (Order $o) => $o->isPaid());
        $filename = str($event->title())->slug()->limit(60, '')->append('-attendees.csv')->toString();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Ticket code', 'Ticket type', 'Name', 'Email', 'Order', 'Paid', 'Complimentary', 'Checked in at', 'Ordered at'], escape: '');

            foreach ($rows as $order) {
                foreach ($order->tickets() as $ticket) {
                    fputcsv($out, array_map([$this, 'cell'], [
                        $ticket['code'],
                        $ticket['type_name'],
                        $ticket['name'],
                        $order->get('buyer.email'),
                        $order->number(),
                        $order->formattedTotal(),
                        $order->isComplimentary() ? 'yes' : '',
                        $ticket['checked_in_at'] ? $this->time($ticket['checked_in_at']) : '',
                        $this->time($order->get('created_at')),
                    ]), escape: '');
                }
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    public function presentOrder(Order $o): array
    {
        $intent = $o->paymentIntent();

        return [
            'id' => $o->id(),
            'number' => $o->number(),
            'name' => $o->get('buyer.name'),
            'email' => $o->get('buyer.email'),
            'tickets' => $o->ticketCount(),
            'lines' => collect($o->get('lines'))->map(fn ($l) => $l['quantity'].' × '.$l['name'])->implode(', '),
            'total' => $o->formattedTotal(),
            'status' => $o->isAwaitingPayment() ? 'awaiting' : ($o->isPending() && ! $o->holdsTickets() ? Order::EXPIRED : $o->status()),
            'comp' => $o->isComplimentary(),
            'late_payment' => (bool) $o->get('late_payment'),
            'partly_refunded' => $o->isPaid() && (int) $o->get('refunded_amount', 0) > 0,
            'checked_in' => $o->checkedIn(),
            'mail_error' => $o->get('mail.error'),
            'mail_sent' => (bool) $o->get('mail.sent_at'),
            'created' => $this->time($o->get('created_at')),
            'can_refund' => $o->canBeRefunded(),
            'can_cancel' => $o->isPaid() && $o->total() === 0,
            'can_resend' => $o->isPaid(),
            'ticket_page' => Urls::order($o),
            'stripe_url' => $intent ? 'https://dashboard.stripe.com/'.(Settings::testMode() ? 'test/' : '').'payments/'.$intent : null,
            'refund_url' => cp_route('karelia-tickets.refund', $o->id()),
            'cancel_url' => cp_route('karelia-tickets.cancel', $o->id()),
            'resend_url' => cp_route('karelia-tickets.resend', $o->id()),
        ];
    }

    private function setup(): array
    {
        $webhook = Cache::get('karelia-tickets:webhook');
        $missing = collect(Settings::collections())->reject(fn ($handle) => Collection::find($handle))->values()->all();

        return [
            'stripe' => Settings::stripeSecret() !== null,
            'testMode' => Settings::testMode(),
            'webhookSecret' => Settings::webhookSecret() !== null,
            'webhookUrl' => Urls::webhook(),
            'webhookEvents' => WebhookController::EVENTS,
            'lastWebhook' => $webhook ? ['at' => $this->time($webhook['at']), 'type' => $webhook['type']] : null,
            'collections' => Settings::collections(),
            'missingCollections' => $missing,
            'currency' => Settings::currency(),
        ];
    }

    private function time(?string $iso): ?string
    {
        return $iso ? Carbon::parse($iso)->setTimezone(config('app.timezone'))->format('Y-m-d H:i') : null;
    }

    /** Stops spreadsheet apps from running cell contents as formulas. */
    private function cell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
