<?php

namespace KareliaLabs\Tickets\Tests;

use Illuminate\Support\Facades\Mail;
use KareliaLabs\Tickets\Mail\TicketsMail;
use KareliaLabs\Tickets\Order;
use KareliaLabs\Tickets\Stripe;
use PHPUnit\Framework\Attributes\Test;

class WebhookTest extends TestCase
{
    private function pendingOrder(int $quantity = 2): Order
    {
        $this->fakeStripe();
        $this->makeEvent();
        $this->buy(['std' => $quantity]);

        return $this->lastOrder();
    }

    #[Test]
    public function unsigned_or_wrongly_signed_events_are_refused()
    {
        Mail::fake();
        $order = $this->pendingOrder();
        $event = $this->sessionEvent('checkout.session.completed', $order);

        $this->webhook($event, 'whsec_wrong')->assertStatus(400);
        $this->webhook($event, 'whsec_test', time() - 600)->assertStatus(400);
        $this->postJson('/!/statamic-tickets/webhook', $event)->assertStatus(400);

        $this->assertSame(Order::PENDING, $this->lastOrder()->status());
        Mail::assertNothingSent();
    }

    #[Test]
    public function a_completed_checkout_issues_the_tickets_once()
    {
        Mail::fake();
        $order = $this->pendingOrder();
        $event = $this->sessionEvent('checkout.session.completed', $order);

        $this->webhook($event)->assertOk()->assertJson(['received' => true]);
        $this->webhook($event)->assertOk();

        $order = $this->lastOrder();
        $this->assertSame(Order::PAID, $order->status());
        $this->assertSame('pi_123', $order->paymentIntent());
        $this->assertCount(2, $order->tickets());
        Mail::assertSent(TicketsMail::class, 1);
    }

    #[Test]
    public function bank_payments_hold_the_tickets_until_they_arrive()
    {
        Mail::fake();
        $order = $this->pendingOrder(3);

        $this->webhook($this->sessionEvent('checkout.session.completed', $order, ['payment_status' => 'unpaid']))->assertOk();
        $this->assertTrue($this->lastOrder()->isAwaitingPayment());
        Mail::assertNothingSent();

        // Days later the hold would have run out, but the seats stay taken.
        $this->travel(3)->days();
        $this->assertTrue($this->lastOrder()->holdsTickets());
        $this->buy(['std' => 1], ['email' => 'grace@example.com'])->assertSessionHasErrors(['tickets'], null, 'tickets');

        $this->webhook($this->sessionEvent('checkout.session.async_payment_succeeded', $order))->assertOk();
        $this->assertSame(Order::PAID, $this->lastOrder()->status());
        $this->assertFalse((bool) $this->lastOrder()->get('late_payment'));
        Mail::assertSent(TicketsMail::class, 1);
    }

    #[Test]
    public function failed_bank_payments_release_the_tickets()
    {
        $order = $this->pendingOrder(3);

        $this->webhook($this->sessionEvent('checkout.session.completed', $order, ['payment_status' => 'unpaid']));
        $this->webhook($this->sessionEvent('checkout.session.async_payment_failed', $order, ['payment_status' => 'unpaid']))->assertOk();

        $this->assertSame(Order::CANCELLED, $this->lastOrder()->status());
        $this->assertFalse($this->lastOrder()->holdsTickets());
    }

    #[Test]
    public function expired_checkouts_release_the_tickets()
    {
        $order = $this->pendingOrder(3);

        $this->webhook($this->sessionEvent('checkout.session.expired', $order, ['status' => 'expired', 'payment_status' => 'unpaid']))->assertOk();

        $this->assertSame(Order::EXPIRED, $this->lastOrder()->status());
    }

    #[Test]
    public function a_payment_after_the_hold_still_gets_tickets_but_is_flagged()
    {
        Mail::fake();
        $order = $this->pendingOrder();
        $this->travel(2)->hours();

        $this->webhook($this->sessionEvent('checkout.session.completed', $order))->assertOk();

        $this->assertSame(Order::PAID, $this->lastOrder()->status());
        $this->assertTrue((bool) $this->lastOrder()->get('late_payment'));
    }

    #[Test]
    public function refunds_made_in_stripe_void_the_tickets()
    {
        Mail::fake();
        $order = $this->pendingOrder();
        $this->webhook($this->sessionEvent('checkout.session.completed', $order));

        $charge = fn (int $refunded) => ['id' => 'evt_r', 'type' => 'charge.refunded', 'data' => ['object' => [
            'id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_123', 'amount' => 5000, 'amount_refunded' => $refunded,
        ]]];

        $this->webhook($charge(2500))->assertOk();
        $order = $this->lastOrder();
        $this->assertSame(Order::PAID, $order->status());
        $this->assertSame(2500, $order->get('refunded_amount'));

        $this->webhook($charge(5000))->assertOk();
        $order = $this->lastOrder();
        $this->assertSame(Order::REFUNDED, $order->status());
        $this->assertSame('void', $order->tickets()[0]['status']);
    }

    #[Test]
    public function sessions_from_other_apps_are_ignored()
    {
        $this->pendingOrder();

        $this->webhook(['id' => 'evt_x', 'type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => 'cs_other', 'client_reference_id' => null, 'metadata' => [], 'status' => 'complete', 'payment_status' => 'paid',
        ]]])->assertOk()->assertJson(['note' => 'Not a Karelia Tickets order.']);

        $this->assertSame(Order::PENDING, $this->lastOrder()->status());
    }

    #[Test]
    public function signatures_follow_stripes_scheme()
    {
        $payload = '{"id":"evt_1"}';
        $now = 1_800_000_000;
        $good = hash_hmac('sha256', $now.'.'.$payload, 'whsec_x');

        $this->assertTrue(Stripe::validSignature($payload, "t={$now},v1={$good}", 'whsec_x', now: $now));
        $this->assertTrue(Stripe::validSignature($payload, "t={$now},v1=deadbeef,v1={$good},v0=old", 'whsec_x', now: $now));
        $this->assertTrue(Stripe::validSignature($payload, "t={$now},v1={$good}", 'whsec_x', now: $now + 299));
        $this->assertFalse(Stripe::validSignature($payload, "t={$now},v1={$good}", 'whsec_x', now: $now + 301));
        $this->assertFalse(Stripe::validSignature($payload.' ', "t={$now},v1={$good}", 'whsec_x', now: $now));
        $this->assertFalse(Stripe::validSignature($payload, "t={$now},v1={$good}", 'whsec_y', now: $now));
        $this->assertFalse(Stripe::validSignature($payload, "v1={$good}", 'whsec_x', now: $now));
        $this->assertFalse(Stripe::validSignature($payload, null, 'whsec_x', now: $now));
    }
}
