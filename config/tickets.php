<?php

/*
|--------------------------------------------------------------------------
| Karelia Tickets
|--------------------------------------------------------------------------
|
| Stripe keys belong in your .env file, never in the Control Panel settings:
| those are saved to resources/addons and usually end up in git. Everything
| else can be changed under Tools > Addons > Karelia Tickets.
|
*/

return [

    'stripe' => [
        'secret' => env('KARELIA_TICKETS_STRIPE_SECRET'),
        'webhook_secret' => env('KARELIA_TICKETS_STRIPE_WEBHOOK_SECRET'),
        'api' => env('KARELIA_TICKETS_STRIPE_API', 'https://api.stripe.com'),
        'version' => env('KARELIA_TICKETS_STRIPE_VERSION', '2026-09-30.endive'),
    ],

    // Collections whose entries can sell tickets. A "Tickets" tab is added to their blueprints.
    'collections' => ['events'],

    // The entry field with the event's start, shown on tickets and in the Control Panel.
    'date_field' => 'start_date',

    'currency' => env('KARELIA_TICKETS_CURRENCY', 'USD'),

    // How long tickets are held while the buyer pays. Stripe needs at least 30 minutes.
    'reservation_minutes' => 30,

    // Where orders and tickets are stored. They contain buyers' names and email
    // addresses, so they live in storage, outside git.
    'path' => storage_path('statamic/karelia-tickets'),

];
