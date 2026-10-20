<?php

namespace KareliaLabs\Tickets\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use KareliaLabs\Tickets\Calendar;
use KareliaLabs\Tickets\Event;
use KareliaLabs\Tickets\Order;
use KareliaLabs\Tickets\OrderRepository;
use KareliaLabs\Tickets\Qr;
use KareliaLabs\Tickets\Settings;
use KareliaLabs\Tickets\Stripe;
use KareliaLabs\Tickets\TicketOffice;
use KareliaLabs\Tickets\Urls;
use Statamic\Facades\Site;

/**
 * The buyer's side: the ticket form posts here, Stripe sends them back here,
 * and the ticket page with the QR codes lives here.
 */
class CheckoutController extends Controller
{
    public function checkout(Request $request, TicketOffice $office, Stripe $stripe)
    {
        $back = fn (array $errors) => back()
            ->withInput($request->except(['_token', 'kt_website']))
            ->withErrors($errors, 'tickets');

        $event = Event::find((string) $request->input('event'));

        if (! $event) {
            return $back(['tickets' => __('This event was not found.')]);
        }

        // Honeypot: people do not fill in fields they cannot see.
        if (filled($request->input('kt_website'))) {
            return $back(['tickets' => __('Something went wrong. Please try again.')]);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'quantity' => ['required', 'array'],
            'quantity.*' => ['nullable', 'integer', 'min:0', 'max:100'],
        ], [], [
            'name' => __('name'),
            'email' => __('email'),
        ]);

        if ($validator->fails()) {
            return $back($validator->errors()->toArray());
        }

        $quantities = (array) $request->input('quantity', []);

        if ($this->needsPayment($event, $quantities) && ! $stripe->configured()) {
            return $back(['tickets' => __('Online payments are not set up yet. Please contact the organiser.')]);
        }

        try {
            $order = $office->reserve($event, $quantities, [
                'name' => trim((string) $request->input('name')),
                'email' => strtolower(trim((string) $request->input('email'))),
            ], Site::current()?->handle());
        } catch (ValidationException $e) {
            return $back($e->errors());
        }

        if ($order->isFree()) {
            return redirect(Urls::order($office->fulfil($order)));
        }

        // Stripe keeps a Checkout Session open for at least 30 minutes.
        $expiresAt = now()->addMinutes(Settings::reservationMinutes())->addSeconds(30)->getTimestamp();

        try {
            $session = $stripe->createCheckout($order, Urls::stripeReturn($order), Urls::stripeCancel($order), $expiresAt);
        } catch (\Throwable $e) {
            report($e);
            $office->release($order, Order::CANCELLED);

            return $back(['tickets' => __('The payment page could not be opened. Please try again in a moment.')]);
        }

        $office->attachCheckout($order, $session['id'], $expiresAt);

        return redirect()->away($session['url'], 303);
    }

    /** Stripe sends the buyer here after paying. */
    public function return(Request $request, string $order, string $token, TicketOffice $office)
    {
        $order = $this->orderOrFail($order, $token);
        $session = (string) $request->query('session_id', '');

        if ($order->isPending() && $session !== '' && hash_equals((string) $order->get('stripe.session'), $session)) {
            $office->syncFromStripe($order);
        }

        return redirect(Urls::order($order));
    }

    /** The buyer went back from Stripe without paying: their tickets go back on sale. */
    public function cancel(string $order, string $token, TicketOffice $office, Stripe $stripe)
    {
        $order = $this->orderOrFail($order, $token);

        if ($order->isPending() && ! $order->isAwaitingPayment()) {
            if ($session = $order->get('stripe.session')) {
                rescue(fn () => $stripe->expireCheckout($session), report: false);
            }

            // Paid in another tab after all? Then the buyer gets their tickets.
            $order = $office->syncFromStripe($order);

            if ($order->isPending()) {
                $order = $office->release($order, Order::CANCELLED);
            }

            if (! $order->isPaid() && ! $order->isAwaitingPayment()) {
                return redirect($order->get('event.url') ?: url('/'))
                    ->with('karelia_tickets_message', __('Your order was cancelled and nothing was charged.'));
            }
        }

        return redirect(Urls::order($order));
    }

    public function show(string $order, string $token, TicketOffice $office)
    {
        $order = $this->orderOrFail($order, $token);

        // While a payment is being confirmed, ask Stripe at most every 5 seconds.
        if ($order->isPending() && Cache::add('karelia-tickets:sync:'.$order->id(), 1, 5)) {
            $order = $office->syncFromStripe($order);
        }

        $tickets = array_map(fn (array $ticket) => $ticket + [
            'qr' => $order->isPaid() && $ticket['status'] === 'valid' ? Qr::svg($ticket['code']) : null,
        ], $order->tickets());

        return response()
            ->view('statamic-tickets::order', [
                'order' => $order,
                'tickets' => $tickets,
                'event' => Event::find($order->eventId()),
                'calendarUrl' => $order->isPaid() && $order->get('event.date') ? Urls::calendar($order) : null,
                'refresh' => $order->isPending() && ! $order->isAwaitingPayment() && $order->holdsTickets(),
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'no-store, private');
    }

    public function calendar(string $order, string $token)
    {
        $order = $this->orderOrFail($order, $token);
        $ics = $order->isPaid() ? Calendar::ics($order) : null;

        abort_unless($ics, 404);

        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="event.ics"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function orderOrFail(string $id, string $token): Order
    {
        $order = app(OrderRepository::class)->find($id);

        abort_unless($order && hash_equals($order->token(), $token), 404);

        return $order;
    }

    private function needsPayment(Event $event, array $quantities): bool
    {
        foreach ($quantities as $typeId => $quantity) {
            if ((int) $quantity > 0 && ($event->type((string) $typeId)['price'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }
}
