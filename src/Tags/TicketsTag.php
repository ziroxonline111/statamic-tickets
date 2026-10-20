<?php

namespace KareliaLabs\Tickets\Tags;

use KareliaLabs\Tickets\Event;
use KareliaLabs\Tickets\Money;
use KareliaLabs\Tickets\Settings;
use KareliaLabs\Tickets\Urls;
use Statamic\Tags\Concerns\RendersForms;
use Statamic\Tags\Tags;

/**
 * {{ tickets:form }}       the ticket form for the current event, ready to use
 * {{ tickets:form }}...{{ /tickets:form }}   your own markup inside the form
 * {{ tickets:types }}...{{ /tickets:types }} ticket types with prices and what is left
 * {{ tickets:sold_out }}   true when nothing is left
 * {{ tickets:on_sale }}    true when tickets can be bought
 *
 * All of them use the current entry, or event="entry-id".
 */
class TicketsTag extends Tags
{
    use RendersForms;

    protected static $handle = 'tickets';

    public function index()
    {
        return $this->form();
    }

    public function form()
    {
        $event = $this->event();

        if (! $event) {
            return '';
        }

        $data = $this->data($event);

        if (! $this->isPair) {
            return view('statamic-tickets::form', $data)->render();
        }

        $html = $this->formOpen(Urls::checkout(), 'POST', ['event', 'class', 'id'], array_filter([
            'class' => $this->params->get('class'),
            'id' => $this->params->get('id'),
        ]));

        $html .= '<input type="hidden" name="event" value="'.e($event->id()).'">';
        $html .= '<div style="position:absolute;left:-10000px" aria-hidden="true"><input type="text" name="kt_website" tabindex="-1" autocomplete="off"></div>';

        return $html.$this->parse($data).'</form>';
    }

    public function types()
    {
        $event = $this->event();

        return $event ? $this->data($event)['types'] : [];
    }

    public function soldOut()
    {
        return ($event = $this->event()) ? $event->soldOut() : false;
    }

    public function onSale()
    {
        return ($event = $this->event()) ? $event->selling() && ! $event->soldOut() : false;
    }

    private function event(): ?Event
    {
        $id = $this->params->get('event') ?? $this->context->value('id');

        return Event::find(is_string($id) ? $id : (string) $id);
    }

    private function data(Event $event): array
    {
        $currency = Settings::currency();
        $errors = session('errors')?->getBag('tickets');
        $old = (array) old('quantity', []);
        $types = [];

        foreach ($event->types() as $id => $type) {
            $left = $event->left($id);
            $onSale = $event->onSale($type);
            $max = $left === null ? $type['max_per_order'] : min($type['max_per_order'], $left);

            $types[] = [
                'id' => $id,
                'name' => $type['name'],
                'description' => $type['description'],
                'price' => Money::toMajor($type['price'], $currency),
                'price_formatted' => $type['price'] > 0 ? Money::format($type['price'], $currency) : __('Free'),
                'free' => $type['price'] === 0,
                'left' => $left,
                'few_left' => $left !== null && $left > 0 && $left <= 10,
                'sold_out' => $left === 0,
                'on_sale' => $onSale && $left !== 0,
                'sales_ended' => ! $onSale,
                'sales_end' => $type['sales_end']?->toIso8601String(),
                'max' => max(0, $max),
                'options' => range(0, max(0, $max)),
                'input_name' => 'quantity['.$id.']',
                'old_quantity' => (int) ($old[$id] ?? 0),
            ];
        }

        return [
            'event' => $event->id(),
            'title' => $event->title(),
            'date' => $event->dateLabel(),
            'types' => $types,
            'selling' => $event->selling(),
            'sold_out' => $event->soldOut(),
            'on_sale' => $event->selling() && ! $event->soldOut(),
            'any_paid' => collect($types)->contains(fn ($t) => ! $t['free']),
            'currency' => $currency,
            'action' => Urls::checkout(),
            'errors' => $errors ? $errors->all() : [],
            'error' => $errors ? $errors->toArray() : [],
            'has_errors' => $errors && $errors->any(),
            'message' => session('karelia_tickets_message'),
            'old_name' => old('name'),
            'old_email' => old('email'),
            'button' => $this->params->get('button'),
        ];
    }
}
