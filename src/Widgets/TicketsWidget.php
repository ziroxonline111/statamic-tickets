<?php

namespace KareliaLabs\Tickets\Widgets;

use KareliaLabs\Tickets\Event;
use KareliaLabs\Tickets\Report;
use Statamic\Facades\User;
use Statamic\Widgets\VueComponent;
use Statamic\Widgets\Widget;

/**
 * Dashboard widget: the next events and how full they are.
 *
 * config/statamic/cp.php:
 *   'widgets' => [['type' => 'tickets', 'width' => 50]],
 */
class TicketsWidget extends Widget
{
    protected static $handle = 'tickets';

    public function component()
    {
        $user = User::current();

        if (! $user || (! $user->isSuper() && ! $user->hasPermission('view tickets'))) {
            return null;
        }

        $report = app(Report::class);
        $limit = (int) $this->config('limit', 4);

        return VueComponent::render('karelia-tickets-widget', [
            'title' => $this->config('title', __('Tickets')),
            'events' => $report->events()
                ->map(fn (Event $e) => $report->summary($e))
                ->reject(fn ($e) => $e['past'])
                ->take($limit)
                ->map(fn ($e) => [
                    'title' => $e['title'],
                    'date_label' => $e['date_label'],
                    'sold' => $e['sold'],
                    'capacity' => $e['capacity'],
                    'revenue' => $e['revenue_formatted'],
                    'url' => cp_route('karelia-tickets.show', $e['id']),
                ])
                ->values()
                ->all(),
            'recent' => $report->recent(7),
            'url' => cp_route('karelia-tickets.index'),
        ]);
    }
}
