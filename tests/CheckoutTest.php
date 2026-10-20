<?php

namespace KareliaLabs\Tickets\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use KareliaLabs\Tickets\Mail\TicketsMail;
use KareliaLabs\Tickets\Order;
use KareliaLabs\Tickets\Urls;
use PHPUnit\Framework\Attributes\Test;

class CheckoutTest extends TestCase
{
    #[Test]
    public function free_tickets_are_issued_and_emailed_right_away()
    {
        Mail::fake();
        $this->fakeStripe();
        $this->makeEvent();

        $response = $this->buy(['guest' => 2]);

        $order = $this->lastOrder();
        $response->assertRedirect(Urls::order($order));

        $this->assertSame(Order::PAID, $order->status());
        $this->assertSame('ada@example.com', $order->get('buyer.email'));
        $this->assertCount(2, $order->tickets());
        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{8}$/', $order->tickets()[0]['code']);
        $this->assertNotSame($order->tickets()[0]['code'], $order->tickets()[1]['code']);
        $this->assertSame('1001', $order->number());
        Http::assertNothingSent();

        Mail::assertSent(TicketsMail::class, fn (TicketsMail $mail) => $mail->hasTo('ada@example.com'));

        $page = $this->get(Urls::order($order))->assertOk();
        $page->assertSee('Summer Gig')
            ->assertSee(substr($order->tickets()[0]['code'], 0, 4))
            ->assertSee('<svg', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    #[Test]
    public function paid_tickets_open_a_stripe_checkout_and_are_held()
    {
        $this->fakeStripe();
        $this->makeEvent();

        $response = $this->buy(['std' => 2]);

        $response->assertStatus(303)->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');

        $order = $this->lastOrder();
        $this->assertSame(Order::PENDING, $order->status());
        $this->assertSame(5000, $order->total());
        $this->assertSame('cs_test_1', $order->get('stripe.session'));
        $this->assertTrue($order->holdsTickets());
        $this->assertSame([], $order->tickets());

        Http::assertSent(function (Request $request) use ($order) {
            return $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
                && $request->method() === 'POST'
                && $request->hasHeader('Stripe-Version', '2026-09-30.endive')
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('sk_test_123:'))
                && $request['mode'] === 'payment'
                && $request['client_reference_id'] === $order->id()
                && $request['metadata']['order_id'] === $order->id()
                && $request['customer_email'] === 'ada@example.com'
                && $request['line_items'][0]['quantity'] == 2
                && $request['line_items'][0]['price_data']['unit_amount'] == 2500
                && $request['line_items'][0]['price_data']['currency'] === 'eur'
                && $request['expires_at'] >= now()->addMinutes(30)->getTimestamp()
                && str_contains($request['success_url'], '{CHECKOUT_SESSION_ID}')
                && ! isset($request['payment_method_types']);
        });
    }

    #[Test]
    public function the_last_tickets_cannot_be_sold_twice()
    {
        $this->fakeStripe();
        $this->makeEvent();

        $this->buy(['std' => 2]);
        $this->buy(['std' => 2], ['email' => 'grace@example.com'])
            ->assertRedirect('/events/gig')
            ->assertSessionHasErrors(['tickets' => 'Only 1 Standard ticket is left.'], null, 'tickets');

        $this->assertCount(1, $this->orders());

        // Unpaid holds run out, then the tickets are back on sale.
        $this->travel(40)->minutes();
        $this->buy(['std' => 3], ['email' => 'grace@example.com'])->assertStatus(303);
        $this->buy(['std' => 1], ['email' => 'alan@example.com'])
            ->assertSessionHasErrors(['tickets' => 'Standard is sold out.'], null, 'tickets');
    }

    #[Test]
    public function orders_are_checked_before_anything_is_held()
    {
        $this->fakeStripe();
        $this->makeEvent([
            'ticket_types' => [
                ['id' => 'std', 'name' => 'Standard', 'price' => 25, 'quantity' => 50, 'max_per_order' => 4],
                ['id' => 'early', 'name' => 'Early bird', 'price' => 15, 'quantity' => 10, 'sales_end' => '2020-01-01 00:00'],
            ],
        ]);

        $this->buy(['std' => 5])->assertSessionHasErrors(['tickets' => 'You can buy at most 4 Standard tickets per order.'], null, 'tickets');
        $this->buy(['early' => 1])->assertSessionHasErrors(['tickets' => 'Sales for Early bird have ended.'], null, 'tickets');
        $this->buy(['std' => 0])->assertSessionHasErrors(['tickets' => 'Choose at least one ticket.'], null, 'tickets');
        $this->buy(['std' => 1], ['email' => 'not-an-email'])->assertSessionHasErrors(['email'], null, 'tickets');
        $this->buy(['std' => 1], ['name' => ''])->assertSessionHasErrors(['name'], null, 'tickets');
        $this->buy(['std' => 1], ['kt_website' => 'http://spam.example'])->assertSessionHasErrors(['tickets'], null, 'tickets');
        $this->buy(['std' => 1], ['event' => 'nope'])->assertSessionHasErrors(['tickets' => 'This event was not found.'], null, 'tickets');

        $this->assertCount(0, $this->orders());
        Http::assertNothingSent();
    }

    #[Test]
    public function events_that_do_not_sell_tickets_take_no_orders()
    {
        $this->fakeStripe();
        $this->makeEvent(['tickets_enabled' => false]);

        $this->buy(['std' => 1])->assertSessionHasErrors(['tickets' => 'Tickets for this event are not on sale.'], null, 'tickets');
        $this->assertCount(0, $this->orders());
    }

    #[Test]
    public function paid_tickets_need_stripe_but_free_ones_do_not()
    {
        Mail::fake();
        config(['statamic.tickets.stripe.secret' => null]);
        $this->makeEvent();

        $this->buy(['std' => 1])->assertSessionHasErrors(['tickets' => 'Online payments are not set up yet. Please contact the organiser.'], null, 'tickets');
        $this->buy(['guest' => 1])->assertRedirect();

        $this->assertSame(Order::PAID, $this->lastOrder()->status());
    }

    #[Test]
    public function a_stripe_error_releases_the_tickets()
    {
        $this->fakeStripe(['api.stripe.com/v1/checkout/sessions' => Http::response(['error' => ['message' => 'Invalid currency']], 400)]);
        $this->makeEvent();

        $this->buy(['std' => 3])->assertSessionHasErrors(['tickets'], null, 'tickets');

        $this->assertSame(Order::CANCELLED, $this->lastOrder()->status());
        $this->assertFalse($this->lastOrder()->holdsTickets());
    }

    #[Test]
    public function returning_from_stripe_issues_the_tickets()
    {
        Mail::fake();
        $this->fakeStripe();
        $this->makeEvent();
        $this->buy(['std' => 2]);
        $order = $this->lastOrder();

        $this->stripeSession = ['status' => 'complete', 'payment_status' => 'paid'];

        $this->get('/!/statamic-tickets/return/'.$order->id().'/'.$order->token().'?session_id=cs_test_1')
            ->assertRedirect(Urls::order($order));

        $order = $this->lastOrder();
        $this->assertSame(Order::PAID, $order->status());
        $this->assertSame('pi_123', $order->paymentIntent());
        $this->assertCount(2, $order->tickets());
        Mail::assertSent(TicketsMail::class, 1);

        // The webhook arriving afterwards changes nothing and sends no second email.
        $this->webhook($this->sessionEvent('checkout.session.completed', $order))->assertOk();
        $this->assertCount(2, $this->lastOrder()->tickets());
        Mail::assertSent(TicketsMail::class, 1);
    }

    #[Test]
    public function the_ticket_page_needs_the_right_token()
    {
        Mail::fake();
        $this->makeEvent();
        $this->buy(['guest' => 1]);
        $order = $this->lastOrder();

        $this->get('/!/statamic-tickets/orders/'.$order->id().'/wrong-token')->assertNotFound();
        $this->get('/!/statamic-tickets/orders/ord_nope/'.$order->token())->assertNotFound();
        $this->get(Urls::calendar($order))->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->assertSee('DTSTART:20261201T193000Z', false)
            ->assertSee('SUMMARY:Summer Gig', false);
    }

    #[Test]
    public function going_back_from_stripe_releases_the_tickets()
    {
        $this->fakeStripe();
        $this->makeEvent();
        $this->buy(['std' => 3]);
        $order = $this->lastOrder();

        $this->stripeSession = ['status' => 'expired', 'payment_status' => 'unpaid'];

        $this->get('/!/statamic-tickets/cancel/'.$order->id().'/'.$order->token())
            ->assertRedirect('http://localhost/events/gig')
            ->assertSessionHas('karelia_tickets_message');

        $this->assertFalse($this->lastOrder()->holdsTickets());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/checkout/sessions/cs_test_1/expire'));

        $this->buy(['std' => 3], ['email' => 'grace@example.com'])->assertStatus(303);
    }

    #[Test]
    public function the_form_tag_lists_ticket_types_with_prices_and_stock()
    {
        $this->makeEvent();
        $entry = \Statamic\Facades\Entry::find('gig');

        $html = $this->renderTag('{{ tickets:form }}', $entry);

        $this->assertStringContainsString('name="quantity[std]"', $html);
        $this->assertStringContainsString('name="quantity[guest]"', $html);
        $this->assertStringContainsString('€25.00', $html);
        $this->assertStringContainsString('Free', $html);
        $this->assertStringContainsString('Only 3 left', $html);
        $this->assertStringContainsString('name="kt_website"', $html);
        $this->assertStringContainsString('/!/statamic-tickets/checkout', $html);
    }

    #[Test]
    public function the_form_tag_says_sold_out()
    {
        $this->makeEvent(['ticket_types' => [['id' => 'std', 'name' => 'Standard', 'price' => 10, 'quantity' => 0]]]);

        $html = $this->renderTag('{{ tickets:form }}', \Statamic\Facades\Entry::find('gig'));

        $this->assertStringContainsString('Sold out', $html);
        $this->assertStringNotContainsString('<form', $html);
    }

    #[Test]
    public function the_form_tag_pair_wraps_custom_markup()
    {
        $this->makeEvent();

        $html = $this->renderTag('{{ tickets:form class="my-form" }}{{ types }}<p>{{ name }}: {{ price_formatted }} ({{ left ?? "∞" }})</p>{{ /types }}<button>Buy</button>{{ /tickets:form }}', \Statamic\Facades\Entry::find('gig'));

        $this->assertStringContainsString('<form method="POST" action="http://localhost/!/statamic-tickets/checkout" class="my-form">', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('<input type="hidden" name="event" value="gig">', $html);
        $this->assertStringContainsString('<p>Standard: €25.00 (3)</p>', $html);
        $this->assertStringContainsString('<p>Guest list: Free (∞)</p>', $html);
    }

    /** Renders the template as a site view would, where tags are allowed. */
    private function renderTag(string $template, $entry): string
    {
        $dir = storage_path('framework/testing/karelia-tickets-views');
        \Illuminate\Support\Facades\File::ensureDirectoryExists($dir);
        $name = 'tag'.md5($template);
        file_put_contents($dir.'/'.$name.'.antlers.html', $template);
        view()->addLocation($dir);

        return (string) view($name, ['id' => $entry->id(), 'title' => $entry->value('title')])->render();
    }
}
