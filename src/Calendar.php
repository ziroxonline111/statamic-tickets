<?php

namespace KareliaLabs\Tickets;

use Illuminate\Support\Carbon;

/**
 * A one-event iCalendar file so buyers can add the event to their calendar.
 */
final class Calendar
{
    public static function ics(Order $order): ?string
    {
        $date = $order->get('event.date');

        if (! $date) {
            return null;
        }

        try {
            $start = Carbon::parse($date);
        } catch (\Throwable) {
            return null;
        }

        $allDay = $start->format('H:i:s') === '00:00:00';
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Karelia Labs//Karelia Tickets//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.$order->id().'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'karelia-tickets'),
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
        ];

        if ($allDay) {
            $lines[] = 'DTSTART;VALUE=DATE:'.$start->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.$start->copy()->addDay()->format('Ymd');
        } else {
            $lines[] = 'DTSTART:'.$start->copy()->utc()->format('Ymd\THis\Z');
            $lines[] = 'DTEND:'.$start->copy()->addHours(2)->utc()->format('Ymd\THis\Z');
        }

        $lines[] = 'SUMMARY:'.self::escape($order->eventTitle());
        $lines[] = 'DESCRIPTION:'.self::escape(__('Order :number, :count tickets. Your tickets: :url', [
            'number' => $order->number(),
            'count' => $order->ticketCount(),
            'url' => Urls::order($order),
        ]));

        if ($url = $order->get('event.url')) {
            $lines[] = 'URL:'.$url;
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $lines))."\r\n";
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $text);
    }

    /** Lines longer than 75 octets are folded, as RFC 5545 asks. */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = '';
        $current = '';

        foreach (mb_str_split($line) as $char) {
            if (strlen($current.$char) > ($out === '' ? 75 : 74)) {
                $out .= ($out === '' ? '' : "\r\n ").$current;
                $current = '';
            }

            $current .= $char;
        }

        return $out.($out === '' ? '' : "\r\n ").$current;
    }
}
