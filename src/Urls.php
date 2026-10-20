<?php

namespace KareliaLabs\Tickets;

/**
 * Front-end URLs live under the site's action route, /!/statamic-tickets/...
 */
final class Urls
{
    public static function base(): string
    {
        return url('/'.trim((string) config('statamic.routes.action', '!'), '/').'/statamic-tickets');
    }

    public static function checkout(): string
    {
        return self::base().'/checkout';
    }

    public static function order(Order $order): string
    {
        return self::base().'/orders/'.$order->id().'/'.$order->token();
    }

    public static function calendar(Order $order): string
    {
        return self::order($order).'/event.ics';
    }

    /** Stripe fills in {CHECKOUT_SESSION_ID} itself. */
    public static function stripeReturn(Order $order): string
    {
        return self::base().'/return/'.$order->id().'/'.$order->token().'?session_id={CHECKOUT_SESSION_ID}';
    }

    public static function stripeCancel(Order $order): string
    {
        return self::base().'/cancel/'.$order->id().'/'.$order->token();
    }

    public static function webhook(): string
    {
        return self::base().'/webhook';
    }
}
