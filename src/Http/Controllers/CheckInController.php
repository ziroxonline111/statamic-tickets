<?php

namespace KareliaLabs\Tickets\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use KareliaLabs\Tickets\Event;
use KareliaLabs\Tickets\Report;
use KareliaLabs\Tickets\TicketOffice;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Check-in at the door, made for a phone: scan the QR code with the camera
 * or type the code.
 */
class CheckInController extends CpController
{
    public function index(Request $request, Report $report)
    {
        $this->authorize('check in attendees');

        $events = $report->events()
            ->map(fn (Event $e) => $report->summary($e))
            ->reject(fn ($e) => $e['past'] && ! $this->today($e['date']))
            ->values();

        $selected = $request->query('event');
        $selected = $events->firstWhere('id', $selected) ? $selected : ($events->count() === 1 ? $events->first()['id'] : null);

        return Inertia::render('statamic-tickets::TicketsCheckIn', [
            'events' => $events->map(fn ($e) => [
                'id' => $e['id'],
                'title' => $e['title'],
                'date_label' => $e['date_label'],
                'sold' => $e['sold'],
                'checked_in' => $e['checked_in'],
                'url' => cp_route('karelia-tickets.check-in', ['event' => $e['id']]),
            ])->all(),
            'selected' => $selected,
            'urls' => [
                'store' => cp_route('karelia-tickets.check-in.store'),
                'undo' => cp_route('karelia-tickets.check-in.undo'),
                'index' => cp_route('karelia-tickets.check-in'),
            ],
        ]);
    }

    public function store(Request $request, TicketOffice $office, Report $report)
    {
        $this->authorize('check in attendees');

        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'event' => ['nullable', 'string'],
        ]);

        $user = User::current();
        $result = $office->checkIn($data['code'], $data['event'] ?? null, $user ? ($user->name() ?: $user->email()) : null);

        if (! empty($result['checked_in_at'])) {
            $result['checked_in_at'] = Carbon::parse($result['checked_in_at'])->setTimezone(config('app.timezone'))->format('H:i');
        }

        if (($data['event'] ?? null) && $event = Event::find($data['event'])) {
            $summary = $report->summary($event);
            $result['counts'] = ['sold' => $summary['sold'], 'checked_in' => $summary['checked_in']];
        }

        return response()->json($result);
    }

    public function undo(Request $request, TicketOffice $office)
    {
        $this->authorize('check in attendees');

        $code = (string) $request->validate(['code' => ['required', 'string', 'max:64']])['code'];

        return response()->json(['ok' => $office->undoCheckIn($code)]);
    }

    private function today(?string $date): bool
    {
        return $date && Carbon::parse($date)->isToday();
    }
}
