<?php

namespace KareliaLabs\Tickets;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use KareliaLabs\Tickets\Mail\TicketsMail;

/**
 * Everything that changes an order: holding tickets while the buyer pays,
 * issuing them once paid, refunds, cancellations and check-in. Changes to one
 * event's tickets happen under a lock, so two buyers cannot take the last seat.
 */
class TicketOffice
{
    /** Letters and digits that cannot be mistaken for each other (no 0/O, 1/I/L). */
    public const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(protected OrderRepository $orders, protected Stripe $stripe) {}

    /**
     * Holds the chosen tickets for a buyer while they pay.
     *
     * @param  array<string, int|string>  $quantities  ticket type id => quantity
     * @param  array{name: string, email: string}  $buyer
     *
     * @throws ValidationException
     */
    public function reserve(Event $event, array $quantities, array $buyer, ?string $site = null): Order
    {
        $quantities = array_filter(array_map(fn ($q) => (int) $q, $quantities), fn ($q) => $q > 0);

        if (! $event->selling()) {
            throw ValidationException::withMessages(['tickets' => __('Tickets for this event are not on sale.')]);
        }

        if ($quantities === []) {
            throw ValidationException::withMessages(['tickets' => __('Choose at least one ticket.')]);
        }

        return $this->forEvent($event->id(), function () use ($event, $quantities, $buyer, $site) {
            $lines = [];

            foreach ($quantities as $typeId => $quantity) {
                $type = $event->type((string) $typeId);

                if (! $type) {
                    throw ValidationException::withMessages(['tickets' => __('That ticket type is no longer available.')]);
                }

                if (! $event->onSale($type)) {
                    throw ValidationException::withMessages(['tickets' => __('Sales for :name have ended.', ['name' => $type['name']])]);
                }

                if ($quantity > $type['max_per_order']) {
                    throw ValidationException::withMessages(['tickets' => __('You can buy at most :max :name tickets per order.', ['max' => $type['max_per_order'], 'name' => $type['name']])]);
                }

                $left = $event->left($type['id']);

                if ($left !== null && $quantity > $left) {
                    throw ValidationException::withMessages(['tickets' => $left === 0
                        ? __(':name is sold out.', ['name' => $type['name']])
                        : trans_choice('Only :left :name ticket is left.|Only :left :name tickets are left.', $left, ['left' => $left, 'name' => $type['name']]),
                    ]);
                }

                $lines[] = ['type' => $type['id'], 'name' => $type['name'], 'unit' => $type['price'], 'quantity' => $quantity];
            }

            $order = Order::make($this->eventSummary($event), $buyer, $lines, Settings::currency());
            $order->set('site', $site);
            $order->set('expires_at', now()->addMinutes(Settings::reservationMinutes())->toIso8601String());

            return $this->orders->save($order);
        });
    }

    /**
     * Marks an order as paid, issues its tickets and emails them. Safe to call
     * more than once: the webhook and the buyer's return can both arrive.
     */
    public function fulfil(Order $order, array $stripe = []): Order
    {
        $newlyPaid = false;

        $order = $this->forOrder($order->id(), function () use ($order, $stripe, &$newlyPaid) {
            $order = $this->orders->find($order->id()) ?? $order;

            foreach (array_filter($stripe) as $key => $value) {
                $order->set('stripe.'.$key, $value);
            }

            if ($order->isPaid() || $order->status() === Order::REFUNDED) {
                return $this->orders->save($order);
            }

            if (! $order->holdsTickets()) {
                // Paid after the hold ran out: the buyer has paid, so they get
                // their tickets, but the organiser should check the capacity.
                $order->set('late_payment', true);
            }

            $order->set('status', Order::PAID)
                ->set('awaiting', false)
                ->set('paid_at', now()->toIso8601String());

            $this->issueTickets($order);
            $newlyPaid = true;

            return $this->orders->save($order);
        });

        if ($newlyPaid) {
            $this->sendTickets($order);
        }

        return $order;
    }

    /** Links the Stripe Checkout Session and holds the tickets a little longer than it lasts. */
    public function attachCheckout(Order $order, string $sessionId, int $expiresAt): Order
    {
        return $this->forOrder($order->id(), function () use ($order, $sessionId, $expiresAt) {
            $order = $this->orders->find($order->id()) ?? $order;
            $order->set('stripe.session', $sessionId)
                ->set('expires_at', now()->setTimestamp($expiresAt + 300)->toIso8601String());

            return $this->orders->save($order);
        });
    }

    /**
     * Applies what a Checkout Session says about the payment. Used by the
     * webhook, by the buyer's return from Stripe and by the ticket page.
     */
    public function applyCheckoutSession(Order $order, array $session): Order
    {
        $intent = $session['payment_intent'] ?? null;
        $stripe = [
            'session' => $session['id'] ?? null,
            'payment_intent' => is_array($intent) ? ($intent['id'] ?? null) : $intent,
            'amount_total' => $session['amount_total'] ?? null,
        ];

        if (in_array($session['payment_status'] ?? null, ['paid', 'no_payment_required'], true)) {
            return $this->fulfil($order, $stripe);
        }

        return match ($session['status'] ?? null) {
            'complete' => $this->awaitPayment($order, $stripe),
            'expired' => $this->release($order, Order::EXPIRED),
            default => $order,
        };
    }

    /** Asks Stripe how a pending order's payment is going. */
    public function syncFromStripe(Order $order): Order
    {
        if (! $order->isPending() || ! $order->get('stripe.session') || ! $this->stripe->configured()) {
            return $order;
        }

        try {
            $session = $this->stripe->retrieveCheckout($order->get('stripe.session'));
        } catch (\Throwable) {
            return $order;
        }

        return $this->applyCheckoutSession($order, $session);
    }

    /** Stripe accepted the order but a bank payment has not arrived yet. */
    public function awaitPayment(Order $order, array $stripe = []): Order
    {
        return $this->forOrder($order->id(), function () use ($order, $stripe) {
            $order = $this->orders->find($order->id()) ?? $order;

            if (! $order->isPending()) {
                return $order;
            }

            foreach (array_filter($stripe) as $key => $value) {
                $order->set('stripe.'.$key, $value);
            }

            return $this->orders->save($order->set('awaiting', true));
        });
    }

    /** Releases the tickets of an order that was never paid. */
    public function release(Order $order, string $status = Order::EXPIRED): Order
    {
        return $this->forOrder($order->id(), function () use ($order, $status) {
            $order = $this->orders->find($order->id()) ?? $order;

            if (! $order->isPending()) {
                return $order;
            }

            return $this->orders->save($order->set('status', $status)->set('awaiting', false));
        });
    }

    /**
     * Refunds a paid order in full through Stripe and voids its tickets. Free and
     * complimentary orders are cancelled instead.
     *
     * @throws \RuntimeException when Stripe refuses the refund
     */
    public function refund(Order $order, ?string $by = null): Order
    {
        if (! $order->isPaid()) {
            throw new \RuntimeException(__('Only paid orders can be refunded.'));
        }

        if ($order->total() === 0) {
            return $this->cancel($order, $by);
        }

        if (! $order->paymentIntent()) {
            throw new \RuntimeException(__('This order has no Stripe payment to refund.'));
        }

        try {
            $refund = $this->stripe->refund($order->paymentIntent());
        } catch (\Throwable $e) {
            throw new \RuntimeException(Stripe::message($e), 0, $e);
        }

        return $this->markRefunded($order, $order->total(), $refund['id'] ?? null, $by);
    }

    /** Records a refund made here or in the Stripe Dashboard. A full refund voids the tickets. */
    public function markRefunded(Order $order, int $amount, ?string $refundId = null, ?string $by = null): Order
    {
        return $this->forOrder($order->id(), function () use ($order, $amount, $refundId, $by) {
            $order = $this->orders->find($order->id()) ?? $order;

            if ($order->status() === Order::REFUNDED) {
                return $order;
            }

            $order->set('refunded_amount', min($order->total(), max($amount, (int) $order->get('refunded_amount', 0))));

            if ($refundId) {
                $order->set('stripe.refund', $refundId);
            }

            if ($order->get('refunded_amount') >= $order->total()) {
                $order->set('status', Order::REFUNDED)
                    ->set('refunded_at', now()->toIso8601String())
                    ->set('refunded_by', $by);
                $this->voidTickets($order);
            }

            return $this->orders->save($order);
        });
    }

    /** Cancels a free or complimentary order, or a pending one. Its tickets stop working. */
    public function cancel(Order $order, ?string $by = null): Order
    {
        return $this->forOrder($order->id(), function () use ($order, $by) {
            $order = $this->orders->find($order->id()) ?? $order;

            if (in_array($order->status(), [Order::CANCELLED, Order::REFUNDED, Order::EXPIRED], true)) {
                return $order;
            }

            if ($order->isPaid() && $order->total() > 0) {
                throw new \RuntimeException(__('Refund paid orders instead, so the buyer gets their money back.'));
            }

            $order->set('status', Order::CANCELLED)->set('cancelled_at', now()->toIso8601String())->set('cancelled_by', $by);
            $this->voidTickets($order);

            return $this->orders->save($order);
        });
    }

    /**
     * Free tickets handed out from the Control Panel: guests, press, staff.
     * They count towards the event's capacity.
     */
    public function complimentary(Event $event, string $typeId, int $quantity, array $guest, bool $email, ?string $by = null): Order
    {
        $type = $event->type($typeId);

        if (! $type) {
            throw ValidationException::withMessages(['type' => __('Choose a ticket type.')]);
        }

        $order = $this->forEvent($event->id(), function () use ($event, $type, $quantity, $guest, $by) {
            $order = Order::make($this->eventSummary($event), $guest, [
                ['type' => $type['id'], 'name' => $type['name'], 'unit' => 0, 'quantity' => max(1, min(100, $quantity))],
            ], Settings::currency());

            $order->set('comp', true)
                ->set('status', Order::PAID)
                ->set('paid_at', now()->toIso8601String())
                ->set('created_by', $by);

            $this->issueTickets($order);

            return $this->orders->save($order);
        });

        if ($email && ! empty($guest['email'])) {
            $this->sendTickets($order);
        }

        return $order;
    }

    /** Emails the tickets. Failures are recorded on the order, never thrown at the buyer. */
    public function sendTickets(Order $order): bool
    {
        $order = $this->orders->find($order->id()) ?? $order;

        try {
            $mail = Mail::to($order->get('buyer.email'), $order->get('buyer.name'));

            if ($copy = Settings::orderCopyTo()) {
                $mail->bcc($copy);
            }

            $mail->send(new TicketsMail($order));

            $order->set('mail', ['sent_at' => now()->toIso8601String(), 'error' => null]);
            $sent = true;
        } catch (\Throwable $e) {
            report($e);
            $order->set('mail', ['sent_at' => null, 'error' => mb_substr($e->getMessage(), 0, 300)]);
            $sent = false;
        }

        $this->forOrder($order->id(), function () use ($order) {
            $fresh = $this->orders->find($order->id()) ?? $order;

            return $this->orders->save($fresh->set('mail', $order->get('mail')));
        });

        return $sent;
    }

    /**
     * Checks a ticket in at the door.
     *
     * @return array{result: string, message: string, ticket?: array, order?: string, event?: string, checked_in_at?: string}
     */
    public function checkIn(string $code, ?string $eventId = null, ?string $by = null): array
    {
        $code = $this->normaliseCode($code);

        if ($code === '') {
            return ['result' => 'unknown', 'message' => __('No ticket code.')];
        }

        $this->orders->flush();

        if (! $found = $this->orders->findTicket($code)) {
            return ['result' => 'unknown', 'message' => __('No ticket with code :code.', ['code' => $code])];
        }

        // Two scanners can check in two tickets from the same order at once.
        return $this->forOrder($found[0]->id(), function () use ($found, $code, $eventId, $by) {
            $order = $this->orders->find($found[0]->id()) ?? $found[0];
            $ticket = $order->ticket($code) ?? $found[1];
            $base = ['ticket' => $ticket, 'order' => $order->number(), 'event' => $order->eventTitle(), 'buyer' => $order->get('buyer.name')];

            if ($eventId && $order->eventId() !== $eventId) {
                return $base + ['result' => 'wrong_event', 'message' => __('This ticket is for :event.', ['event' => $order->eventTitle()])];
            }

            if (! $order->isPaid() || ($ticket['status'] ?? 'valid') !== 'valid') {
                return $base + ['result' => 'void', 'message' => match ($order->status()) {
                    Order::REFUNDED => __('This ticket was refunded.'),
                    Order::CANCELLED => __('This ticket was cancelled.'),
                    default => __('This ticket is not paid.'),
                }];
            }

            if (! empty($ticket['checked_in_at'])) {
                return $base + ['result' => 'already', 'checked_in_at' => $ticket['checked_in_at'], 'message' => __('Already checked in.')];
            }

            $now = now()->toIso8601String();
            $order->updateTicket($code, ['checked_in_at' => $now, 'checked_in_by' => $by]);
            $this->orders->save($order);

            return array_merge($base, [
                'result' => 'ok',
                'message' => __('Welcome!'),
                'ticket' => $order->ticket($code),
                'checked_in_at' => $now,
            ]);
        });
    }

    public function undoCheckIn(string $code): bool
    {
        $code = $this->normaliseCode($code);
        $this->orders->flush();

        if ($code === '' || ! $found = $this->orders->findTicket($code)) {
            return false;
        }

        return $this->forOrder($found[0]->id(), function () use ($found, $code) {
            $order = $this->orders->find($found[0]->id()) ?? $found[0];
            $order->updateTicket($code, ['checked_in_at' => null, 'checked_in_by' => null]);
            $this->orders->save($order);

            return true;
        });
    }

    /** Accepts "k7mx-q2pa", spaces and the like from scanners and keyboards. */
    public function normaliseCode(string $code): string
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');

        return strlen($code) <= 32 ? $code : '';
    }

    private function issueTickets(Order $order): void
    {
        if ($order->tickets() !== []) {
            return;
        }

        $tickets = [];
        $taken = [];

        foreach ($order->get('lines') as $line) {
            for ($i = 0; $i < (int) $line['quantity']; $i++) {
                do {
                    $code = $this->newCode();
                } while (isset($taken[$code]) || $this->orders->codeExists($code));

                $taken[$code] = true;
                $tickets[] = [
                    'code' => $code,
                    'type' => $line['type'],
                    'type_name' => $line['name'],
                    'name' => $order->get('buyer.name'),
                    'status' => 'valid',
                    'checked_in_at' => null,
                    'checked_in_by' => null,
                ];
            }
        }

        $order->set('tickets', $tickets);
    }

    private function voidTickets(Order $order): void
    {
        foreach ($order->tickets() as $ticket) {
            $order->updateTicket($ticket['code'], ['status' => 'void']);
        }
    }

    private function newCode(): string
    {
        $code = '';
        $max = strlen(self::CODE_ALPHABET) - 1;

        for ($i = 0; $i < 8; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    private function eventSummary(Event $event): array
    {
        return array_merge($event->summary(), ['date_label' => $event->dateLabel(), 'note' => $event->note()]);
    }

    private function forEvent(string $eventId, Closure $callback): mixed
    {
        return $this->locked('karelia-tickets:event:'.$eventId, function () use ($callback) {
            $this->orders->flush();

            return $callback();
        });
    }

    private function forOrder(string $orderId, Closure $callback): mixed
    {
        return $this->locked('karelia-tickets:order:'.$orderId, function () use ($callback) {
            $this->orders->flush();

            return $callback();
        });
    }

    private function locked(string $key, Closure $callback): mixed
    {
        try {
            return Cache::lock($key, 20)->block(10, $callback);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['tickets' => __('Many people are buying right now. Please try again.')]);
        }
    }
}
