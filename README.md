# Karelia Tickets

Sell event tickets from your Statamic site with Stripe Checkout. Buyers pick their tickets on your event page, pay with Stripe and get QR tickets by email. At the door you check them in with a phone.

- **Ticket types per event**: early bird, standard, student, free guest list. Each with a price, how many there are, when sales end and the most per order.
- **No overselling**: tickets are held while the buyer pays and released if they give up. The last seat cannot be sold twice.
- **Stripe Checkout**: cards, Apple Pay, Google Pay and the other methods you switch on in Stripe. Bank payments that arrive days later hold their tickets until they do.
- **QR tickets** on a printable ticket page, sent by email with the codes and a calendar file.
- **Check-in on a phone**: scan with the camera or type the code. Green for welcome, amber for already in, red for refunded or the wrong event.
- **Control Panel**: sales per event and ticket type, orders, attendee list as CSV, refunds through Stripe, free tickets for guests and press, a dashboard widget.
- **No per-ticket fees**: the money goes straight to your own Stripe account. Stripe's normal card fees apply.

## Requirements

- Statamic 6, PHP 8.3 or newer
- A Stripe account (free events work without one)
- Email set up in Laravel (`MAIL_MAILER`), so the tickets can be sent

## Installation

```bash
composer require karelialabs/statamic-tickets
```

## Setup

### 1. Stripe keys go in `.env`

```env
KARELIA_TICKETS_STRIPE_SECRET=sk_live_...
KARELIA_TICKETS_STRIPE_WEBHOOK_SECRET=whsec_...
KARELIA_TICKETS_CURRENCY=EUR
```

Use a restricted key if you like. It needs write access to Checkout Sessions and Refunds. With an `sk_test_` key everything runs in Stripe's test mode and the Control Panel says so.

### 2. The Stripe webhook

The webhook lets Stripe tell your site about a payment even when the buyer closes the tab before coming back. In the Stripe Dashboard, go to **Developers → Webhooks → Add endpoint**:

- **Endpoint URL**: `https://your-site.com/!/statamic-tickets/webhook` (shown under **Tools → Tickets**)
- **Events**: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, `charge.refunded`

Copy the endpoint's signing secret into `KARELIA_TICKETS_STRIPE_WEBHOOK_SECRET`. Events without a valid signature are refused.

### 3. Your events

Karelia Tickets adds a **Tickets** tab to the entries of your events collection, `events` by default. Choose another collection, the date field and the currency under **Tools → Addons → Karelia Tickets → Settings**.

Open an event, switch on **Sell tickets** and add ticket types:

| Field | |
|---|---|
| Name | e.g. Early bird, Standard, Student |
| Price | `0` for free tickets |
| Tickets available | Leave empty for no limit |
| Sales end | Optional date and time |
| Most per order | Default 10 |
| Description | Shown under the name |

The event's date comes from its `start_date` field (or the field you choose), or the entry date.

### 4. The ticket form

Put the tag in your event template:

```antlers
{{ tickets:form }}
```

It shows the ticket types with prices, what is left, a name and email field and the total, and sends the buyer to Stripe. It follows your site's font and colours. Set `--kt-accent` to brand the button:

```css
.kt { --kt-accent: #0f766e; --kt-radius: 0.5rem; }
```

For your own markup, use the tag as a pair. The form, the CSRF token and the event are added for you:

```antlers
{{ tickets:form class="tickets" }}
    {{ if has_errors }}
        <ul class="errors">{{ errors }}<li>{{ value }}</li>{{ /errors }}</ul>
    {{ /if }}

    {{ types }}
        <label>
            {{ name }} – {{ price_formatted }}
            {{ if sold_out }}Sold out{{ elseif few_left }}Only {{ left }} left{{ /if }}
            {{ if on_sale }}
                <select name="{{ input_name }}">
                    {{ options }}<option value="{{ value }}">{{ value }}</option>{{ /options }}
                </select>
            {{ /if }}
        </label>
    {{ /types }}

    <input name="name" value="{{ old_name }}" required>
    <input type="email" name="email" value="{{ old_email }}" required>
    <button>Get tickets</button>
{{ /tickets:form }}
```

Variables inside the pair: `title`, `date`, `types`, `selling`, `sold_out`, `on_sale`, `any_paid`, `currency`, `errors`, `has_errors`, `message`, `old_name`, `old_email`. Each type has `id`, `name`, `description`, `price`, `price_formatted`, `free`, `left`, `few_left`, `sold_out`, `on_sale`, `sales_ended`, `sales_end`, `max`, `options`, `input_name`.

Other tags: `{{ tickets:sold_out }}`, `{{ tickets:on_sale }}` and `{{ tickets:types }}...{{ /tickets:types }}`. All of them use the current entry, or `event="entry-id"`.

To change the ready-made form, the ticket page or the email, publish the views:

```bash
php artisan vendor:publish --tag=statamic-tickets-views
```

## How a purchase works

1. The buyer chooses tickets and enters their name and email. The tickets are held for 30 minutes.
2. They pay on Stripe Checkout.
3. Stripe sends them back to their ticket page and calls the webhook. Whichever comes first issues the tickets; the other changes nothing.
4. They get an email with the ticket codes, a link to the ticket page with the QR codes, and the event as a calendar file.

If they go back from Stripe without paying, the order is cancelled, the Stripe session is closed and the tickets go back on sale. Free tickets skip Stripe and are issued straight away.

## At the door

Open **Tools → Check-in** on a phone, choose the event and press **Scan with camera**, or type the code. Codes are 8 letters and digits without look-alikes (no 0/O, 1/I/L), and dashes, spaces and lower case are fine. Each ticket gets in once; **Undo** reverses a check-in. Several people can check in at the same time.

Give door staff a role with only the **Check in attendees** permission: they see the check-in page and the name on each scanned ticket, not your sales or the attendee list.

## Refunds and free tickets

On an event's page under **Tools → Tickets**, each order has a menu:

- **Refund order** refunds the full amount through Stripe and the tickets stop working. Refunds made in the Stripe Dashboard are picked up by the webhook too. A partial refund keeps the tickets valid.
- **Email tickets again** resends the confirmation.
- **Cancel tickets** for free orders.

**Add free tickets** issues tickets for guests, press or staff without payment, optionally by email. They count towards the number available.

## Where orders are kept

Orders are JSON files in `storage/statamic/karelia-tickets/orders`, one per order, outside git. They hold the buyer's name and email address, the tickets and the Stripe payment ID. No card details ever reach your site. Back up the storage folder with the rest of your site.

## Configuration

Publish the config file to change the defaults:

```bash
php artisan vendor:publish --tag=statamic-tickets-config
```

| Key | Default | |
|---|---|---|
| `stripe.secret` | `KARELIA_TICKETS_STRIPE_SECRET` | Stripe secret or restricted key |
| `stripe.webhook_secret` | `KARELIA_TICKETS_STRIPE_WEBHOOK_SECRET` | Signing secret of the webhook endpoint |
| `stripe.version` | `2026-09-30.endive` | Stripe API version |
| `collections` | `['events']` | Collections that sell tickets |
| `date_field` | `start_date` | Field with the event's start |
| `currency` | `KARELIA_TICKETS_CURRENCY`, `USD` | Must be a currency Stripe supports for your account |
| `reservation_minutes` | `30` | How long tickets are held during checkout (30 to 1440) |
| `path` | `storage/statamic/karelia-tickets` | Where orders are stored |

## Permissions

- **View tickets and orders**: sales, orders and attendee lists
  - **Refund, cancel and resend orders, and add free tickets**
- **Check in attendees**: the check-in page only

## Good to know

- One currency per site. Changing it later does not convert old orders.
- The ticket page link in the email works without logging in. Anyone with the link can see the tickets, like a paper ticket.
- The price you set is what the buyer pays. Karelia Tickets does not add tax on top.
- Camera scanning needs HTTPS (or localhost) and permission for the camera. Typing the code always works.
- On sites with several servers, use a shared cache store (Redis, database) so the locks that stop overselling are shared too.

## Support

support@karelialabs.atlassian.net · Documentation: https://ziroxonline111.github.io/karelia-labs/products/karelia-tickets/docs/

## Licence

Commercial. A licence from the Statamic Marketplace covers one production site; development and staging copies are free. See [LICENSE.md](LICENSE.md).
