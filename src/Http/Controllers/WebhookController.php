<?php

namespace KareliaLabs\Tickets\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use KareliaLabs\Tickets\Order;
use KareliaLabs\Tickets\OrderRepository;
use KareliaLabs\Tickets\Settings;
use KareliaLabs\Tickets\Stripe;
use KareliaLabs\Tickets\TicketOffice;

/**
 * Stripe tells the site about payments here, also when the buyer closes the
 * tab before returning. Every event is checked against the signing secret.
 *
 * Events used: checkout.session.completed, checkout.session.async_payment_succeeded,
 * checkout.session.async_payment_failed, checkout.session.expired, charge.refunded.
 */
class WebhookController extends Controller
{
    public const EVENTS = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed',
        'checkout.session.expired',
        'charge.refunded',
    ];

    public function __invoke(Request $request, TicketOffice $office, OrderRepository $orders)
    {
        $secret = Settings::webhookSecret();

        if (! $secret) {
            return response()->json(['error' => 'KARELIA_TICKETS_STRIPE_WEBHOOK_SECRET is not set.'], 400);
        }

        $payload = $request->getContent();

        if (! Stripe::validSignature($payload, $request->header('Stripe-Signature'), $secret)) {
            return response()->json(['error' => 'Invalid signature.'], 400);
        }

        $event = json_decode($payload, true);
        $object = $event['data']['object'] ?? [];
        $type = $event['type'] ?? '';

        Cache::forever('karelia-tickets:webhook', ['at' => now()->toIso8601String(), 'type' => $type, 'livemode' => (bool) ($event['livemode'] ?? false)]);

        if (str_starts_with($type, 'checkout.session.')) {
            $order = $this->orderForSession($object, $orders);

            if (! $order) {
                return response()->json(['received' => true, 'note' => 'Not a Karelia Tickets order.']);
            }

            if ($type === 'checkout.session.async_payment_failed') {
                $office->release($order, Order::CANCELLED);
            } else {
                $office->applyCheckoutSession($order, $object);
            }
        }

        if ($type === 'charge.refunded' && ($intent = $object['payment_intent'] ?? null)) {
            $order = $orders->all()->first(fn (Order $o) => $o->paymentIntent() === $intent);

            if ($order) {
                $office->markRefunded($order, (int) ($object['amount_refunded'] ?? 0), null, 'Stripe');
            }
        }

        return response()->json(['received' => true]);
    }

    private function orderForSession(array $session, OrderRepository $orders): ?Order
    {
        $id = $session['metadata']['order_id'] ?? $session['client_reference_id'] ?? null;
        $order = $id ? $orders->find((string) $id) : null;
        $order ??= isset($session['id']) ? $orders->findByStripeSession((string) $session['id']) : null;

        // A session that is not the order's current one says nothing about it.
        if ($order && $order->get('stripe.session') && isset($session['id']) && $order->get('stripe.session') !== $session['id']) {
            return null;
        }

        return $order;
    }
}
