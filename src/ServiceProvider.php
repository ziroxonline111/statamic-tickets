<?php

namespace KareliaLabs\Tickets;

use KareliaLabs\Tickets\Listeners\AddTicketsTab;
use KareliaLabs\Tickets\Tags\TicketsTag;
use KareliaLabs\Tickets\Widgets\TicketsWidget;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $config = false;

    protected $vite = [
        'input' => [
            'resources/js/addon.js',
            'resources/css/addon.css',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
        'actions' => __DIR__.'/../routes/actions.php',
    ];

    protected $tags = [
        TicketsTag::class,
    ];

    protected $widgets = [
        TicketsWidget::class,
    ];

    protected $listen = [
        EntryBlueprintFound::class => [AddTicketsTab::class],
    ];

    public function register()
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/tickets.php', 'statamic.tickets');

        $this->app->singleton(OrderRepository::class);
        $this->app->singleton(Stripe::class);
        $this->app->singleton(TicketOffice::class);
    }

    public function bootAddon()
    {
        $this->publishes([
            __DIR__.'/../config/tickets.php' => config_path('statamic/tickets.php'),
        ], 'statamic-tickets-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/statamic-tickets'),
        ], 'statamic-tickets-views');

        $this->registerSettingsBlueprint(fn () => Settings::blueprint());

        Permission::extend(function () {
            Permission::group('tickets', __('Tickets'), function () {
                Permission::register('view tickets', function ($permission) {
                    $permission
                        ->label(__('View tickets and orders'))
                        ->description(__('See sales, orders and attendee lists, which include buyers\' names and email addresses.'))
                        ->children([
                            Permission::make('manage ticket orders')
                                ->label(__('Refund, cancel and resend orders, and add free tickets')),
                        ]);
                });

                Permission::register('check in attendees')
                    ->label(__('Check in attendees'))
                    ->description(__('Use the check-in page at the door. Shows the name on each ticket that is scanned.'));
            });
        });

        Nav::extend(function ($nav) {
            $nav->tools(__('Tickets'))
                ->route('karelia-tickets.index')
                ->icon('media-ticket')
                ->can('view tickets');

            $nav->tools(__('Check-in'))
                ->route('karelia-tickets.check-in')
                ->icon('clipboard-check')
                ->can('check in attendees');
        });
    }
}
