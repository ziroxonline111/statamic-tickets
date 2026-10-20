<?php

namespace KareliaLabs\Tickets;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One order: who bought what for which event, its payment and its tickets.
 * Stored as one JSON file per order.
 */
final class Order
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const EXPIRED = 'expired';

    public const REFUNDED = 'refunded';

    public const CANCELLED = 'cancelled';

    public function __construct(public array $data) {}

    public static function make(array $event, array $buyer, array $lines, string $currency): self
    {
        return new self([
            'id' => 'ord_'.Str::lower((string) Str::ulid()),
            'number' => null,
            'token' => Str::random(40),
            'status' => self::PENDING,
            'event' => $event,
            'buyer' => $buyer,
            'lines' => array_values($lines),
            'total' => array_sum(array_map(fn ($l) => $l['unit'] * $l['quantity'], $lines)),
            'currency' => strtoupper($currency),
            'stripe' => [],
            'tickets' => [],
            'comp' => false,
            'awaiting' => false,
            'created_at' => now()->toIso8601String(),
            'expires_at' => null,
            'paid_at' => null,
            'refunded_at' => null,
            'site' => null,
        ]);
    }

    public function id(): string
    {
        return $this->data['id'];
    }

    public function number(): string
    {
        return (string) ($this->data['number'] ?? Str::upper(substr($this->id(), -6)));
    }

    public function token(): string
    {
        return $this->data['token'];
    }

    public function status(): string
    {
        return $this->data['status'];
    }

    public function eventId(): string
    {
        return (string) $this->data['event']['id'];
    }

    public function eventTitle(): string
    {
        return (string) ($this->data['event']['title'] ?? '');
    }

    public function total(): int
    {
        return (int) $this->data['total'];
    }

    public function currency(): string
    {
        return $this->data['currency'];
    }

    public function isFree(): bool
    {
        return $this->total() === 0;
    }

    public function isPaid(): bool
    {
        return $this->status() === self::PAID;
    }

    public function isPending(): bool
    {
        return $this->status() === self::PENDING;
    }

    /** Waiting for a bank payment that Stripe confirms later (days, sometimes). */
    public function isAwaitingPayment(): bool
    {
        return $this->isPending() && (bool) ($this->data['awaiting'] ?? false);
    }

    public function isComplimentary(): bool
    {
        return (bool) ($this->data['comp'] ?? false);
    }

    /** Pending orders hold their tickets until they expire, or while a bank payment is on its way. */
    public function holdsTickets(): bool
    {
        if ($this->isPaid() || $this->isAwaitingPayment()) {
            return true;
        }

        return $this->isPending()
            && ! empty($this->data['expires_at'])
            && Carbon::parse($this->data['expires_at'])->isFuture();
    }

    public function paymentIntent(): ?string
    {
        return $this->get('stripe.payment_intent') ?: null;
    }

    public function canBeRefunded(): bool
    {
        return $this->isPaid() && $this->total() > 0 && $this->paymentIntent() !== null;
    }

    public function checkedIn(): int
    {
        return count(array_filter($this->data['tickets'], fn ($t) => ! empty($t['checked_in_at'])));
    }

    /** Tickets per type held by this order. */
    public function quantities(): array
    {
        $quantities = [];

        foreach ($this->data['lines'] as $line) {
            $quantities[$line['type']] = ($quantities[$line['type']] ?? 0) + (int) $line['quantity'];
        }

        return $quantities;
    }

    public function ticketCount(): int
    {
        return array_sum($this->quantities());
    }

    public function tickets(): array
    {
        return $this->data['tickets'];
    }

    public function ticket(string $code): ?array
    {
        foreach ($this->data['tickets'] as $ticket) {
            if (hash_equals($ticket['code'], $code)) {
                return $ticket;
            }
        }

        return null;
    }

    public function updateTicket(string $code, array $changes): void
    {
        foreach ($this->data['tickets'] as $i => $ticket) {
            if ($ticket['code'] === $code) {
                $this->data['tickets'][$i] = array_merge($ticket, $changes);
            }
        }
    }

    public function set(string $key, mixed $value): self
    {
        data_set($this->data, $key, $value);

        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    public function formattedTotal(): string
    {
        return $this->isFree() ? __('Free') : Money::format($this->total(), $this->currency());
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
