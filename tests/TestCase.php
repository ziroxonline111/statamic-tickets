<?php

namespace KareliaLabs\Tickets\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use KareliaLabs\Tickets\Order;
use KareliaLabs\Tickets\OrderRepository;
use KareliaLabs\Tickets\ServiceProvider;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    /** What the fake Stripe says when a Checkout Session is read back. */
    protected array $stripeSession = ['status' => 'open', 'payment_status' => 'unpaid'];

    protected int $sessions = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'statamic.tickets.stripe.secret' => 'sk_test_123',
            'statamic.tickets.stripe.webhook_secret' => 'whsec_test',
            'statamic.tickets.currency' => 'EUR',
            'statamic.tickets.path' => storage_path('framework/testing/karelia-tickets'),
        ]);

        File::deleteDirectory(storage_path('framework/testing/karelia-tickets'));
        app(OrderRepository::class)->flush();

        Http::preventStrayRequests();
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('statamic.editions.pro', true);
    }

    protected function makeEvent(array $data = [], string $id = 'gig', string $collection = 'events')
    {
        if (! Collection::find($collection)) {
            Collection::make($collection)->routes('/'.$collection.'/{slug}')->save();
        }

        $entry = Entry::make()
            ->collection($collection)
            ->id($id)
            ->slug($id)
            ->data(array_merge([
                'title' => 'Summer Gig',
                'start_date' => '2026-12-01 19:30',
                'tickets_enabled' => true,
                'ticket_types' => [
                    ['id' => 'std', 'name' => 'Standard', 'price' => 25, 'quantity' => 3, 'max_per_order' => 5],
                    ['id' => 'guest', 'name' => 'Guest list', 'price' => 0, 'quantity' => null],
                ],
            ], $data));

        $entry->save();

        return $entry;
    }

    protected function fakeStripe(array $overrides = []): void
    {
        Http::fake(array_merge([
            'api.stripe.com/v1/checkout/sessions/*/expire' => Http::response(['id' => 'cs_test_1', 'status' => 'expired']),
            'api.stripe.com/v1/checkout/sessions/*' => fn (Request $request) => Http::response(array_merge([
                'id' => basename(parse_url($request->url(), PHP_URL_PATH)),
                'object' => 'checkout.session',
                'payment_intent' => 'pi_123',
                'amount_total' => 5000,
            ], $this->stripeSession)),
            'api.stripe.com/v1/checkout/sessions' => function () {
                $this->sessions++;

                return Http::response([
                    'id' => 'cs_test_'.$this->sessions,
                    'url' => 'https://checkout.stripe.com/c/pay/cs_test_'.$this->sessions,
                ]);
            },
            'api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded']),
        ], $overrides));
    }

    protected function buy(array $quantities, array $buyer = []): TestResponse
    {
        return $this->from('/events/gig')->post('/!/statamic-tickets/checkout', array_merge([
            'event' => 'gig',
            'name' => 'Ada Lovelace',
            'email' => 'Ada@Example.com',
            'quantity' => $quantities,
        ], $buyer));
    }

    protected function orders(): \Illuminate\Support\Collection
    {
        $repo = app(OrderRepository::class);
        $repo->flush();

        return $repo->all();
    }

    protected function lastOrder(): ?Order
    {
        return $this->orders()->first();
    }

    protected function webhook(array $event, string $secret = 'whsec_test', ?int $time = null): TestResponse
    {
        $payload = json_encode($event);
        $time ??= time();
        $signature = hash_hmac('sha256', $time.'.'.$payload, $secret);

        return $this->call('POST', '/!/statamic-tickets/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$time},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    protected function sessionEvent(string $type, Order $order, array $session = []): array
    {
        return [
            'id' => 'evt_'.uniqid(),
            'type' => $type,
            'livemode' => false,
            'data' => ['object' => array_merge([
                'id' => $order->get('stripe.session'),
                'object' => 'checkout.session',
                'client_reference_id' => $order->id(),
                'metadata' => ['order_id' => $order->id()],
                'status' => 'complete',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_123',
                'amount_total' => $order->total(),
            ], $session)],
        ];
    }
}
