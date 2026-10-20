<?php

namespace KareliaLabs\Tickets;

use Statamic\Facades\Addon;

/**
 * Settings from the Control Panel (Tools > Addons > Karelia Tickets) win over
 * config/statamic/tickets.php. Stripe keys only ever come from config (.env).
 */
final class Settings
{
    public const PACKAGE = 'karelialabs/statamic-tickets';

    public static function stripeSecret(): ?string
    {
        $value = trim((string) config('statamic.tickets.stripe.secret'));

        return $value !== '' ? $value : null;
    }

    public static function webhookSecret(): ?string
    {
        $value = trim((string) config('statamic.tickets.stripe.webhook_secret'));

        return $value !== '' ? $value : null;
    }

    public static function testMode(): bool
    {
        return str_starts_with((string) self::stripeSecret(), 'sk_test_') || str_starts_with((string) self::stripeSecret(), 'rk_test_');
    }

    public static function currency(): string
    {
        $currency = strtoupper(trim((string) self::get('currency', config('statamic.tickets.currency', 'USD'))));

        return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'USD';
    }

    /** @return array<int, string> */
    public static function collections(): array
    {
        return array_values(array_filter((array) self::get('collections', config('statamic.tickets.collections', ['events']))));
    }

    public static function dateField(): string
    {
        return (string) (self::get('date_field') ?: config('statamic.tickets.date_field', 'start_date'));
    }

    public static function reservationMinutes(): int
    {
        // Stripe Checkout sessions last at least 30 minutes.
        return max(30, min(1440, (int) config('statamic.tickets.reservation_minutes', 30)));
    }

    public static function orderCopyTo(): ?string
    {
        $email = trim((string) self::get('order_copy_to', ''));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    public static function ticketNote(): ?string
    {
        $note = trim((string) self::get('ticket_note', ''));

        return $note !== '' ? $note : null;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $settings = Addon::get(self::PACKAGE)?->settings();

            if (! $settings || ! array_key_exists($key, $settings->raw())) {
                return $default;
            }

            $value = $settings->get($key);
        } catch (\Throwable) {
            return $default;
        }

        return $value ?? $default;
    }

    public static function blueprint(): array
    {
        return [
            'tabs' => [
                'main' => [
                    'sections' => [
                        [
                            'display' => __('Events'),
                            'fields' => [
                                [
                                    'handle' => 'collections',
                                    'field' => [
                                        'type' => 'collections',
                                        'display' => __('Collections that sell tickets'),
                                        'instructions' => __('Entries in these collections get a Tickets tab where you add ticket types.'),
                                        'mode' => 'select',
                                        'default' => config('statamic.tickets.collections', ['events']),
                                    ],
                                ],
                                [
                                    'handle' => 'date_field',
                                    'field' => [
                                        'type' => 'text',
                                        'display' => __('Event date field'),
                                        'instructions' => __('The field with the event start, shown on tickets. Leave empty to use start_date, or the entry date.'),
                                        'width' => 50,
                                    ],
                                ],
                                [
                                    'handle' => 'currency',
                                    'field' => [
                                        'type' => 'text',
                                        'display' => __('Currency'),
                                        'instructions' => __('ISO code, e.g. USD, EUR or SEK. Stripe must support it for your account.'),
                                        'default' => config('statamic.tickets.currency', 'USD'),
                                        'width' => 50,
                                        'validate' => 'nullable|size:3',
                                    ],
                                ],
                            ],
                        ],
                        [
                            'display' => __('Tickets and email'),
                            'fields' => [
                                [
                                    'handle' => 'ticket_note',
                                    'field' => [
                                        'type' => 'textarea',
                                        'display' => __('Note on every ticket'),
                                        'instructions' => __('E.g. "Doors open 30 minutes before. Show this ticket on your phone or printed." Each event can add its own note too.'),
                                    ],
                                ],
                                [
                                    'handle' => 'order_copy_to',
                                    'field' => [
                                        'type' => 'text',
                                        'input_type' => 'email',
                                        'display' => __('Send a copy of each order to'),
                                        'instructions' => __('Optional. The organiser gets every confirmation email too.'),
                                        'validate' => 'nullable|email',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** The Tickets tab added to event blueprints. */
    public static function entryFields(): array
    {
        return [
            'tickets_enabled' => [
                'type' => 'toggle',
                'display' => __('Sell tickets'),
                'instructions' => __('Show the ticket form on this event. Switch off to pause sales.'),
            ],
            'ticket_types' => [
                'type' => 'grid',
                'display' => __('Ticket types'),
                'mode' => 'stacked',
                'add_row' => __('Add ticket type'),
                'if' => ['tickets_enabled' => true],
                'fields' => [
                    ['handle' => 'name', 'field' => ['type' => 'text', 'display' => __('Name'), 'instructions' => __('e.g. Early bird, Standard, Student'), 'validate' => 'required', 'width' => 50]],
                    ['handle' => 'price', 'field' => ['type' => 'float', 'display' => __('Price'), 'instructions' => __('0 for free tickets.'), 'width' => 25, 'validate' => 'nullable|numeric|min:0']],
                    ['handle' => 'quantity', 'field' => ['type' => 'integer', 'display' => __('Tickets available'), 'instructions' => __('Leave empty for no limit.'), 'width' => 25, 'validate' => 'nullable|integer|min:0']],
                    ['handle' => 'sales_end', 'field' => ['type' => 'date', 'display' => __('Sales end'), 'time_enabled' => true, 'width' => 50]],
                    ['handle' => 'max_per_order', 'field' => ['type' => 'integer', 'display' => __('Most per order'), 'default' => 10, 'width' => 50, 'validate' => 'nullable|integer|min:1']],
                    ['handle' => 'description', 'field' => ['type' => 'text', 'display' => __('Description'), 'instructions' => __('Shown under the name, e.g. "Includes a drink".')]],
                ],
            ],
            'tickets_note' => [
                'type' => 'textarea',
                'display' => __('Note on this event\'s tickets'),
                'if' => ['tickets_enabled' => true],
            ],
        ];
    }
}
