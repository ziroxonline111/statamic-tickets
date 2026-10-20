<?php

namespace KareliaLabs\Tickets\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use KareliaLabs\Tickets\Calendar;
use KareliaLabs\Tickets\Order;
use KareliaLabs\Tickets\Urls;

/**
 * The confirmation email: the ticket codes, a link to the ticket page with
 * the QR codes, and the event as a calendar file.
 */
class TicketsMail extends Mailable
{
    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Your tickets: :event', ['event' => $this->order->eventTitle()]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'statamic-tickets::emails.tickets',
            text: 'statamic-tickets::emails.tickets-text',
            with: [
                'order' => $this->order,
                'ticketsUrl' => Urls::order($this->order),
                'eventUrl' => $this->order->get('event.url'),
            ],
        );
    }

    public function attachments(): array
    {
        $ics = Calendar::ics($this->order);

        return $ics ? [Attachment::fromData(fn () => $ics, 'event.ics')->withMime('text/calendar')] : [];
    }
}
