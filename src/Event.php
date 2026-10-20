<?php

namespace KareliaLabs\Tickets;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as Entries;

/**
 * An entry that sells tickets, with its ticket types and how many are left.
 */
final class Event
{
    public function __construct(public Entry $entry) {}

    public static function find(?string $id): ?self
    {
        $entry = $id ? Entries::find($id) : null;

        return $entry && in_array($entry->collectionHandle(), Settings::collections(), true) ? new self($entry) : null;
    }

    public function id(): string
    {
        return (string) $this->entry->id();
    }

    public function title(): string
    {
        return (string) $this->entry->value('title');
    }

    public function selling(): bool
    {
        return (bool) $this->entry->value('tickets_enabled') && $this->entry->published() && $this->types() !== [];
    }

    public function date(): ?Carbon
    {
        $value = $this->entry->value(Settings::dateField());

        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
            }
        }

        if (is_array($value) && isset($value['start'])) {
            try {
                return Carbon::parse($value['start']);
            } catch (\Throwable) {
            }
        }

        return $this->entry->hasDate() ? $this->entry->date() : null;
    }

    public function dateLabel(): ?string
    {
        $date = $this->date();

        if (! $date) {
            return null;
        }

        $hasTime = $date->format('H:i') !== '00:00';

        return $date->locale(app()->getLocale())->isoFormat($hasTime ? 'dddd D MMMM YYYY, HH:mm' : 'dddd D MMMM YYYY');
    }

    public function note(): ?string
    {
        $note = trim((string) $this->entry->value('tickets_note'));

        return $note !== '' ? $note : Settings::ticketNote();
    }

    public function url(): ?string
    {
        return $this->entry->absoluteUrl();
    }

    /**
     * @return array<string, array{id: string, name: string, price: int, quantity: ?int, sales_end: ?Carbon, max_per_order: int, description: ?string}>
     */
    public function types(): array
    {
        $rows = $this->entry->value('ticket_types');
        $currency = Settings::currency();
        $types = [];

        foreach (is_iterable($rows) ? $rows : [] as $i => $row) {
            $row = is_array($row) ? $row : (method_exists($row, 'toArray') ? $row->toArray() : (array) $row);
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $id = (string) ($row['id'] ?? '') ?: Str::slug($name).'-'.$i;
            $end = $row['sales_end'] ?? null;

            $types[$id] = [
                'id' => $id,
                'name' => $name,
                'price' => Money::toMinor($row['price'] ?? 0, $currency),
                'quantity' => is_numeric($row['quantity'] ?? null) ? (int) $row['quantity'] : null,
                'sales_end' => $end ? rescue(fn () => Carbon::parse(is_array($end) ? ($end['date'] ?? null).' '.($end['time'] ?? '') : (string) $end), null, false) : null,
                'max_per_order' => max(1, (int) ($row['max_per_order'] ?? 10) ?: 10),
                'description' => ($row['description'] ?? null) ?: null,
            ];
        }

        return $types;
    }

    public function type(string $id): ?array
    {
        return $this->types()[$id] ?? null;
    }

    /** Tickets taken per type: paid, plus pending orders that still hold theirs. */
    public function taken(?string $exceptOrder = null): array
    {
        $taken = [];

        foreach (app(OrderRepository::class)->forEvent($this->id()) as $order) {
            if ($order->id() === $exceptOrder || ! $order->holdsTickets()) {
                continue;
            }

            foreach ($order->quantities() as $type => $quantity) {
                $taken[$type] = ($taken[$type] ?? 0) + $quantity;
            }
        }

        return $taken;
    }

    /** How many of a type can still be bought, or null for no limit. */
    public function left(string $typeId, ?string $exceptOrder = null): ?int
    {
        $type = $this->type($typeId);

        if (! $type || $type['quantity'] === null) {
            return $type ? null : 0;
        }

        return max(0, $type['quantity'] - ($this->taken($exceptOrder)[$typeId] ?? 0));
    }

    public function onSale(array $type): bool
    {
        return ! $type['sales_end'] || $type['sales_end']->isFuture();
    }

    public function soldOut(): bool
    {
        foreach ($this->types() as $id => $type) {
            if ($this->onSale($type) && $this->left($id) !== 0) {
                return false;
            }
        }

        return true;
    }

    public function summary(): array
    {
        return [
            'id' => $this->id(),
            'title' => $this->title(),
            'date' => $this->date()?->toIso8601String(),
            'url' => $this->url(),
        ];
    }
}
