<?php

namespace KareliaLabs\Tickets;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Statamic\Facades\Entry;

/**
 * Numbers for the Control Panel: what each event has sold, held and earned.
 */
class Report
{
    public function __construct(protected OrderRepository $orders) {}

    /** @return Collection<int, Event> upcoming first (soonest at the top), then past events */
    public function events(): Collection
    {
        $withOrders = $this->orders->all()->map(fn (Order $o) => $o->eventId())->unique()->flip();

        return Entry::query()
            ->whereIn('collection', Settings::collections())
            ->get()
            ->map(fn ($entry) => new Event($entry))
            ->filter(fn (Event $e) => $e->entry->value('tickets_enabled') || $withOrders->has($e->id()))
            ->sortBy(function (Event $e) {
                $date = $e->date();

                if (! $date) {
                    return [1, 0];
                }

                return $date->copy()->endOfDay()->isPast() ? [2, -$date->getTimestamp()] : [0, $date->getTimestamp()];
            })
            ->values();
    }

    public function summary(Event $event): array
    {
        $orders = $this->orders->forEvent($event->id());
        $paid = $orders->filter(fn (Order $o) => $o->isPaid());
        $types = $event->types();
        $capacity = $types && collect($types)->every(fn ($t) => $t['quantity'] !== null)
            ? array_sum(array_column($types, 'quantity'))
            : null;

        $sold = $paid->sum(fn (Order $o) => $o->ticketCount());
        $date = $event->date();

        return [
            'id' => $event->id(),
            'title' => $event->title(),
            'date' => $date?->toIso8601String(),
            'date_label' => $event->dateLabel(),
            'past' => $date ? $date->copy()->endOfDay()->isPast() : false,
            'selling' => $event->selling(),
            'sold_out' => $types !== [] && $event->soldOut(),
            'sold' => $sold,
            'held' => $orders->filter(fn (Order $o) => ! $o->isPaid() && $o->holdsTickets())->sum(fn (Order $o) => $o->ticketCount()),
            'capacity' => $capacity,
            'checked_in' => $paid->sum(fn (Order $o) => $o->checkedIn()),
            'orders' => $paid->count(),
            'revenue' => $this->revenue($paid),
            'revenue_formatted' => Money::format($this->revenue($paid), Settings::currency()),
            'edit_url' => $event->entry->editUrl(),
            'url' => $event->url(),
        ];
    }

    public function types(Event $event): array
    {
        $orders = $this->orders->forEvent($event->id());
        $sold = [];
        $held = [];

        foreach ($orders as $order) {
            foreach ($order->quantities() as $type => $quantity) {
                if ($order->isPaid()) {
                    $sold[$type] = ($sold[$type] ?? 0) + $quantity;
                } elseif ($order->holdsTickets()) {
                    $held[$type] = ($held[$type] ?? 0) + $quantity;
                }
            }
        }

        return collect($event->types())->map(fn ($type, $id) => [
            'id' => $id,
            'name' => $type['name'],
            'price' => $type['price'] > 0 ? Money::format($type['price'], Settings::currency()) : __('Free'),
            'capacity' => $type['quantity'],
            'sold' => $sold[$id] ?? 0,
            'held' => $held[$id] ?? 0,
            'left' => $event->left($id),
            'on_sale' => $event->onSale($type),
            'sales_end' => $type['sales_end']?->toIso8601String(),
        ])->values()->all();
    }

    /** Tickets sold and money taken over the last days, all events together. */
    public function recent(int $days = 30): array
    {
        $since = now()->subDays($days);
        $paid = $this->orders->all()->filter(fn (Order $o) => $o->isPaid() && $o->get('paid_at') && Carbon::parse($o->get('paid_at'))->gte($since));

        return [
            'days' => $days,
            'tickets' => $paid->sum(fn (Order $o) => $o->ticketCount()),
            'orders' => $paid->count(),
            'revenue' => Money::format($this->revenue($paid), Settings::currency()),
        ];
    }

    /** Paid totals minus partial refunds. */
    private function revenue(Collection $paid): int
    {
        return (int) $paid->sum(fn (Order $o) => $o->total() - (int) $o->get('refunded_amount', 0));
    }
}
