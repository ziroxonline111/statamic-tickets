<?php

namespace KareliaLabs\Tickets\Http\Controllers;

use Illuminate\Http\Request;
use KareliaLabs\Tickets\Event;
use KareliaLabs\Tickets\OrderRepository;
use KareliaLabs\Tickets\TicketOffice;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

class OrdersController extends CpController
{
    public function refund(string $order, OrderRepository $orders, TicketOffice $office)
    {
        $this->authorize('manage ticket orders');

        $order = $orders->find($order) ?? abort(404);

        try {
            $order = $office->refund($order, $this->by());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => __('Order #:number was refunded. Its tickets no longer work.', ['number' => $order->number()]),
            'order' => app(TicketsController::class)->presentOrder($order),
        ]);
    }

    public function cancel(string $order, OrderRepository $orders, TicketOffice $office)
    {
        $this->authorize('manage ticket orders');

        $order = $orders->find($order) ?? abort(404);

        try {
            $order = $office->cancel($order, $this->by());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => __('Order #:number was cancelled.', ['number' => $order->number()]),
            'order' => app(TicketsController::class)->presentOrder($order),
        ]);
    }

    public function resend(string $order, OrderRepository $orders, TicketOffice $office)
    {
        $this->authorize('manage ticket orders');

        $order = $orders->find($order) ?? abort(404);
        abort_unless($order->isPaid(), 422);

        if (! $office->sendTickets($order)) {
            return response()->json(['message' => __('The email could not be sent: :error', ['error' => $orders->find($order->id())?->get('mail.error')])], 422);
        }

        return response()->json([
            'message' => __('Tickets sent to :email.', ['email' => $order->get('buyer.email')]),
            'order' => app(TicketsController::class)->presentOrder($orders->find($order->id())),
        ]);
    }

    /** Free tickets for guests, press or staff. */
    public function complimentary(string $event, Request $request, TicketOffice $office)
    {
        $this->authorize('manage ticket orders');

        $event = Event::find($event) ?? abort(404);

        $data = $request->validate([
            'type' => ['required', 'string'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'send' => ['boolean'],
        ]);

        $order = $office->complimentary($event, $data['type'], (int) $data['quantity'], [
            'name' => trim($data['name']),
            'email' => strtolower(trim((string) ($data['email'] ?? ''))),
        ], (bool) ($data['send'] ?? false), $this->by());

        return response()->json([
            'message' => trans_choice(':count free ticket added for :name.|:count free tickets added for :name.', $order->ticketCount(), ['count' => $order->ticketCount(), 'name' => $data['name']]),
            'order' => app(TicketsController::class)->presentOrder($order),
        ]);
    }

    private function by(): ?string
    {
        $user = User::current();

        return $user ? ($user->name() ?: $user->email()) : null;
    }
}
