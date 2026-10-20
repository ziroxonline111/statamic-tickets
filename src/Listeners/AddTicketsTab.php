<?php

namespace KareliaLabs\Tickets\Listeners;

use KareliaLabs\Tickets\Settings;
use Statamic\Events\EntryBlueprintFound;

/**
 * Adds the Tickets tab to entries in the collections that sell tickets, so
 * nobody has to edit their blueprints by hand.
 */
class AddTicketsTab
{
    public function handle(EntryBlueprintFound $event): void
    {
        $namespace = (string) $event->blueprint->namespace();

        if (! str_starts_with($namespace, 'collections.')) {
            return;
        }

        if (! in_array(substr($namespace, strlen('collections.')), Settings::collections(), true)) {
            return;
        }

        $event->blueprint->ensureFieldsInTab(Settings::entryFields(), 'tickets');
    }
}
