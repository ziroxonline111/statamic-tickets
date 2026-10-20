import TicketsIndex from './pages/TicketsIndex.vue';
import TicketsEvent from './pages/TicketsEvent.vue';
import TicketsCheckIn from './pages/TicketsCheckIn.vue';
import TicketsWidget from './components/TicketsWidget.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('statamic-tickets::TicketsIndex', TicketsIndex);
    Statamic.$inertia.register('statamic-tickets::TicketsEvent', TicketsEvent);
    Statamic.$inertia.register('statamic-tickets::TicketsCheckIn', TicketsCheckIn);
    Statamic.$components.register('karelia-tickets-widget', TicketsWidget);
});
