<?php

namespace KareliaLabs\Tickets;

use NumberFormatter;

/**
 * Amounts are kept in the currency's smallest unit (cents), like Stripe.
 * Zero-decimal currencies such as JPY use whole units. ISK and UGX are sent to
 * Stripe as two-decimal amounts even though they have no minor unit in use.
 */
final class Money
{
    public const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    public static function factor(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 1 : 100;
    }

    /** "25.50" in EUR becomes 2550. */
    public static function toMinor(mixed $amount, string $currency): int
    {
        if (! is_numeric($amount)) {
            return 0;
        }

        return max(0, (int) round(((float) $amount) * self::factor($currency)));
    }

    public static function toMajor(int $minor, string $currency): float
    {
        return round($minor / self::factor($currency), 2);
    }

    public static function format(int $minor, string $currency, ?string $locale = null): string
    {
        $currency = strtoupper($currency);
        $major = self::toMajor($minor, $currency);

        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter($locale ?? app()->getLocale(), NumberFormatter::CURRENCY);
            $formatted = $formatter->formatCurrency($major, $currency);

            if ($formatted !== false) {
                return $formatted;
            }
        }

        $decimals = self::factor($currency) === 1 ? 0 : 2;

        return number_format($major, $decimals).' '.$currency;
    }
}
