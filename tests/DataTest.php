<?php

namespace KareliaLabs\Tickets\Tests;

use Illuminate\Support\Facades\Mail;
use KareliaLabs\Tickets\Calendar;
use KareliaLabs\Tickets\Mail\TicketsMail;
use KareliaLabs\Tickets\Money;
use KareliaLabs\Tickets\TicketOffice;
use KareliaLabs\Tickets\Urls;
use PHPUnit\Framework\Attributes\Test;

class DataTest extends TestCase
{
    #[Test]
    public function amounts_use_the_currencys_smallest_unit()
    {
        $this->assertSame(2550, Money::toMinor('25.50', 'EUR'));
        $this->assertSame(2500, Money::toMinor(25, 'usd'));
        $this->assertSame(1500, Money::toMinor(1500, 'JPY'));
        $this->assertSame(150000, Money::toMinor(1500, 'ISK'));
        $this->assertSame(0, Money::toMinor('free', 'EUR'));
        $this->assertSame(0, Money::toMinor(-5, 'EUR'));
        $this->assertSame(1999, Money::toMinor(19.99, 'EUR'));
        $this->assertSame(25.5, Money::toMajor(2550, 'EUR'));
        $this->assertSame('€25.50', Money::format(2550, 'EUR', 'en'));
        $this->assertSame('¥1,500', Money::format(1500, 'JPY', 'en'));
    }

    #[Test]
    public function the_email_has_the_codes_and_a_link_to_the_tickets()
    {
        Mail::fake();
        $this->makeEvent(['tickets_note' => "Doors open 19:00.\nBring ID."]);
        $this->buy(['guest' => 2]);
        $order = $this->lastOrder();

        $mail = new TicketsMail($order);
        $html = $mail->render();

        $this->assertSame('Your tickets: Summer Gig', $mail->envelope()->subject);
        $this->assertStringContainsString(Urls::order($order), $html);
        $this->assertStringContainsString($order->tickets()[0]['code'], $html);
        $this->assertStringContainsString($order->tickets()[1]['code'], $html);
        $this->assertStringContainsString('Doors open 19:00.', $html);
        $this->assertCount(1, $mail->attachments());
    }

    #[Test]
    public function calendar_files_follow_the_ical_rules()
    {
        Mail::fake();
        $this->makeEvent(['title' => 'A very long event title, with commas; and semicolons, that goes on and on and on']);
        $this->buy(['guest' => 1]);

        $ics = Calendar::ics($this->lastOrder());

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString('SUMMARY:A very long event title\, with commas\; and semicolons\, that', $ics);
        $this->assertStringContainsString("\r\n ", $ics);

        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
        }
    }

    #[Test]
    public function all_day_events_get_a_date_without_a_time()
    {
        Mail::fake();
        $this->makeEvent(['start_date' => '2026-12-24']);
        $this->buy(['guest' => 1]);

        $ics = Calendar::ics($this->lastOrder());

        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261224', $ics);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20261225', $ics);
    }

    #[Test]
    public function ticket_codes_survive_typing_and_scanning()
    {
        $office = app(TicketOffice::class);

        $this->assertSame('K7MXQ2PA', $office->normaliseCode(' k7mx-q2pa '));
        $this->assertSame('K7MXQ2PA', $office->normaliseCode('K7MX Q2PA'));
        $this->assertSame('', $office->normaliseCode(str_repeat('A', 40)));
    }
}
