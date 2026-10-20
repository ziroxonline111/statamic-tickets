<?php

namespace KareliaLabs\Tickets\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use KareliaLabs\Tickets\Mail\TicketsMail;
use KareliaLabs\Tickets\Order;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

class ControlPanelTest extends TestCase
{
    private function super()
    {
        $user = User::make()->email('super@example.com')->set('name', 'Sam Super')->makeSuper();
        $user->save();

        return $user;
    }

    private function staff(array $permissions, string $email = 'door@example.com')
    {
        Role::make('staff')->title('Staff')->permissions(array_merge(['access cp'], $permissions))->save();

        $user = User::make()->email($email)->assignRole('staff');
        $user->save();

        return $user;
    }

    private function paidOrder(int $quantity = 2, array $stripe = []): Order
    {
        Mail::fake();
        $this->fakeStripe($stripe);
        $this->makeEvent();
        $this->buy(['std' => $quantity]);
        $this->webhook($this->sessionEvent('checkout.session.completed', $this->lastOrder()));

        return $this->lastOrder();
    }

    #[Test]
    public function guests_and_users_without_permission_are_kept_out()
    {
        $this->get(cp_route('karelia-tickets.index'))->assertRedirect();

        $door = $this->staff(['check in attendees']);
        $this->assertContains($this->actingAs($door)->get(cp_route('karelia-tickets.index'))->status(), [302, 403]);
        $this->assertContains($this->actingAs($door)->postJson(cp_route('karelia-tickets.refund', 'ord_01m5d30dykp0fgxd4xchjc7sy5'))->status(), [302, 403]);
        $this->actingAs($door)->get(cp_route('karelia-tickets.check-in'))->assertOk();
    }

    #[Test]
    public function the_overview_lists_events_with_sales()
    {
        $this->paidOrder();

        $this->actingAs($this->super())
            ->get(cp_route('karelia-tickets.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('statamic-tickets::TicketsIndex')
                ->where('events.0.title', 'Summer Gig')
                ->where('events.0.sold', 2)
                ->where('events.0.capacity', null)
                ->where('events.0.revenue_formatted', '€50.00')
                ->where('recent.tickets', 2)
                ->where('setup.stripe', true)
                ->where('setup.testMode', true)
                ->where('setup.webhookSecret', true)
                ->where('setup.webhookUrl', 'http://localhost/!/statamic-tickets/webhook')
                ->where('setup.lastWebhook.type', 'checkout.session.completed'));
    }

    #[Test]
    public function the_event_page_shows_types_orders_and_attendees()
    {
        $order = $this->paidOrder();

        $this->actingAs($this->super())
            ->get(cp_route('karelia-tickets.show', 'gig'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('statamic-tickets::TicketsEvent')
                ->where('types.0.name', 'Standard')
                ->where('types.0.sold', 2)
                ->where('types.0.left', 1)
                ->where('orders.0.number', $order->number())
                ->where('orders.0.status', 'paid')
                ->where('orders.0.can_refund', true)
                ->where('orders.0.stripe_url', 'https://dashboard.stripe.com/test/payments/pi_123')
                ->has('attendees', 2)
                ->where('attendees.0.name', 'Ada Lovelace')
                ->where('canManage', true));
    }

    #[Test]
    public function an_order_can_be_refunded_through_stripe()
    {
        $order = $this->paidOrder();

        $this->actingAs($this->super())
            ->postJson(cp_route('karelia-tickets.refund', $order->id()))
            ->assertOk()
            ->assertJsonPath('order.status', 'refunded');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/refunds' && $r['payment_intent'] === 'pi_123');

        $order = $this->lastOrder();
        $this->assertSame(Order::REFUNDED, $order->status());
        $this->assertSame('Sam Super', $order->get('refunded_by'));
        $this->assertSame('void', $order->tickets()[0]['status']);

        // Stripe's own charge.refunded event later changes nothing.
        $this->webhook(['id' => 'evt_r', 'type' => 'charge.refunded', 'data' => ['object' => ['payment_intent' => 'pi_123', 'amount_refunded' => 5000]]])->assertOk();
        $this->assertSame(Order::REFUNDED, $this->lastOrder()->status());
    }

    #[Test]
    public function a_failed_refund_is_reported_and_changes_nothing()
    {
        $order = $this->paidOrder(2, ['api.stripe.com/v1/refunds' => Http::response(['error' => ['message' => 'Charge already refunded']], 400)]);

        $this->actingAs($this->super())
            ->postJson(cp_route('karelia-tickets.refund', $order->id()))
            ->assertStatus(422)
            ->assertJson(['message' => 'Charge already refunded']);

        $this->assertSame(Order::PAID, $this->lastOrder()->status());
    }

    #[Test]
    public function free_tickets_can_be_added_and_emailed()
    {
        Mail::fake();
        $this->makeEvent();

        $this->actingAs($this->super())
            ->postJson(cp_route('karelia-tickets.complimentary', 'gig'), [
                'type' => 'std', 'quantity' => 2, 'name' => 'Press Desk', 'email' => 'press@example.com', 'send' => true,
            ])
            ->assertOk()
            ->assertJsonPath('order.comp', true);

        $order = $this->lastOrder();
        $this->assertSame(Order::PAID, $order->status());
        $this->assertSame(0, $order->total());
        $this->assertCount(2, $order->tickets());
        Mail::assertSent(TicketsMail::class, fn ($m) => $m->hasTo('press@example.com'));

        // They take seats: one Standard ticket is left.
        $this->fakeStripe();
        $this->buy(['std' => 2])->assertSessionHasErrors(['tickets'], null, 'tickets');

        // And free orders are cancelled rather than refunded.
        $this->actingAs($this->super())->postJson(cp_route('karelia-tickets.cancel', $order->id()))->assertOk();
        $this->assertSame(Order::CANCELLED, $this->lastOrder()->status());
    }

    #[Test]
    public function tickets_can_be_emailed_again()
    {
        $order = $this->paidOrder();

        $this->actingAs($this->super())->postJson(cp_route('karelia-tickets.resend', $order->id()))->assertOk();

        Mail::assertSent(TicketsMail::class, 2);
    }

    #[Test]
    public function the_attendee_list_downloads_as_csv()
    {
        Mail::fake();
        $this->fakeStripe();
        $this->makeEvent();
        $this->buy(['guest' => 1], ['name' => '=HYPERLINK("http://evil.example")']);

        $csv = $this->actingAs($this->super())
            ->get(cp_route('karelia-tickets.export', 'gig'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8')
            ->streamedContent();

        $this->assertStringStartsWith("\u{FEFF}".'"Ticket code","Ticket type",Name,Email', $csv);
        $this->assertStringContainsString('"\'=HYPERLINK(""http://evil.example"")"', $csv);
        $this->assertStringContainsString('ada@example.com', $csv);
    }

    #[Test]
    public function door_staff_check_tickets_in_once()
    {
        $order = $this->paidOrder();
        $code = $order->tickets()[0]['code'];
        $door = $this->staff(['check in attendees']);

        $first = $this->actingAs($door)->postJson(cp_route('karelia-tickets.check-in.store'), ['code' => strtolower(substr($code, 0, 4).'-'.substr($code, 4)), 'event' => 'gig']);
        $first->assertOk()->assertJson(['result' => 'ok', 'buyer' => 'Ada Lovelace', 'counts' => ['sold' => 2, 'checked_in' => 1]]);

        $this->actingAs($door)->postJson(cp_route('karelia-tickets.check-in.store'), ['code' => $code, 'event' => 'gig'])
            ->assertOk()->assertJson(['result' => 'already']);

        $this->actingAs($door)->postJson(cp_route('karelia-tickets.check-in.store'), ['code' => 'ZZZZ2222', 'event' => 'gig'])
            ->assertOk()->assertJson(['result' => 'unknown']);

        $this->makeEvent(['title' => 'Winter Gig'], 'winter');
        $this->actingAs($door)->postJson(cp_route('karelia-tickets.check-in.store'), ['code' => $order->tickets()[1]['code'], 'event' => 'winter'])
            ->assertOk()->assertJson(['result' => 'wrong_event', 'event' => 'Summer Gig']);

        $this->actingAs($door)->postJson(cp_route('karelia-tickets.check-in.undo'), ['code' => $code])->assertOk()->assertJson(['ok' => true]);
        $this->actingAs($door)->postJson(cp_route('karelia-tickets.check-in.store'), ['code' => $code])->assertJson(['result' => 'ok']);

        $this->assertSame('door@example.com', $this->lastOrder()->ticket($code)['checked_in_by']);
    }

    #[Test]
    public function refunded_tickets_do_not_get_in()
    {
        $order = $this->paidOrder();
        $this->actingAs($this->super())->postJson(cp_route('karelia-tickets.refund', $order->id()))->assertOk();

        $this->actingAs($this->super())->postJson(cp_route('karelia-tickets.check-in.store'), ['code' => $order->tickets()[0]['code']])
            ->assertJson(['result' => 'void', 'message' => 'This ticket was refunded.']);
    }

    #[Test]
    public function event_blueprints_get_a_tickets_tab()
    {
        $this->makeEvent();
        $this->makeEvent([], 'about', 'pages');

        $events = \Statamic\Facades\Entry::find('gig')->blueprint();
        $pages = \Statamic\Facades\Entry::find('about')->blueprint();

        $this->assertTrue($events->hasField('ticket_types'));
        $this->assertTrue($events->hasTab('tickets'));
        $this->assertSame('grid', $events->field('ticket_types')->type());
        $this->assertFalse($pages->hasField('ticket_types'));
    }

    #[Test]
    public function the_dashboard_widget_shows_upcoming_events()
    {
        $this->paidOrder();
        $this->actingAs($this->super());

        $widget = new \KareliaLabs\Tickets\Widgets\TicketsWidget;
        $widget->setConfig([]);
        $widget = $widget->component();

        $widget = $widget->toArray();

        $this->assertSame('karelia-tickets-widget', $widget['name']);
        $this->assertSame('Summer Gig', $widget['props']['events'][0]['title']);
        $this->assertSame(2, $widget['props']['events'][0]['sold']);
    }
}
