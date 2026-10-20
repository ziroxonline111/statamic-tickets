<?php

namespace KareliaLabs\Tickets;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * The few Stripe API calls Karelia Tickets needs, over plain HTTPS: Checkout
 * Sessions, reading a session back, refunds, and webhook signatures. Payments
 * go straight to the site owner's own Stripe account.
 */
class Stripe
{
    public function configured(): bool
    {
        return Settings::stripeSecret() !== null;
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('statamic.tickets.stripe.api', 'https://api.stripe.com'), '/'))
            ->withBasicAuth((string) Settings::stripeSecret(), '')
            ->withHeaders(['Stripe-Version' => (string) config('statamic.tickets.stripe.version', '2026-09-30.endive')])
            ->asForm()
            ->acceptJson()
            ->timeout(20)
            ->connectTimeout(10);
    }

    /** @return array{id: string, url: string} */
    public function createCheckout(Order $order, string $successUrl, string $cancelUrl, int $expiresAt): array
    {
        $params = [
            'mode' => 'payment',
            'customer_email' => $order->get('buyer.email'),
            'client_reference_id' => $order->id(),
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'expires_at' => $expiresAt,
            'metadata' => ['order_id' => $order->id(), 'order_number' => $order->number(), 'event' => mb_substr($order->eventTitle(), 0, 400)],
            'payment_intent_data' => [
                'metadata' => ['order_id' => $order->id(), 'order_number' => $order->number()],
                'description' => mb_substr(__('Tickets: :event (order :number)', ['event' => $order->eventTitle(), 'number' => $order->number()]), 0, 400),
            ],
            'line_items' => [],
        ];

        foreach ($order->get('lines') as $line) {
            if ($line['unit'] <= 0) {
                continue;
            }

            $params['line_items'][] = [
                'quantity' => (int) $line['quantity'],
                'price_data' => [
                    'currency' => strtolower($order->currency()),
                    'unit_amount' => (int) $line['unit'],
                    'product_data' => ['name' => mb_substr($order->eventTitle().' – '.$line['name'], 0, 250)],
                ],
            ];
        }

        $session = $this->http()->post('/v1/checkout/sessions', $params)->throw()->json();

        return ['id' => $session['id'], 'url' => $session['url']];
    }

    public function retrieveCheckout(string $sessionId): array
    {
        return $this->http()->get('/v1/checkout/sessions/'.rawurlencode($sessionId))->throw()->json();
    }

    /** Stops an abandoned Checkout Session from being paid later. */
    public function expireCheckout(string $sessionId): void
    {
        $this->http()->post('/v1/checkout/sessions/'.rawurlencode($sessionId).'/expire')->throw();
    }

    public function refund(string $paymentIntent, ?int $amount = null): array
    {
        return $this->http()->post('/v1/refunds', array_filter([
            'payment_intent' => $paymentIntent,
            'amount' => $amount,
            'reason' => 'requested_by_customer',
        ], fn ($v) => $v !== null))->throw()->json();
    }

    /**
     * Checks the Stripe-Signature header: t=timestamp,v1=signature[,v1=...].
     * The signed payload is "timestamp.body", HMAC-SHA256 with the endpoint secret.
     */
    public static function validSignature(string $payload, ?string $header, string $secret, int $tolerance = 300, ?int $now = null): bool
    {
        if (! $header || $secret === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || ! $signatures || abs(($now ?? time()) - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /** A readable message from a failed Stripe call. */
    public static function message(\Throwable $e): string
    {
        if ($e instanceof RequestException && $e->response) {
            return (string) ($e->response->json('error.message') ?: $e->response->status());
        }

        return $e->getMessage();
    }
}
