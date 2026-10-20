<script setup>
import { computed } from 'vue';
import { Head, Link } from '@statamic/cms/inertia';
import {
    Alert,
    Badge,
    Button,
    Card,
    Description,
    Header,
    Heading,
    Input,
    Panel,
    PanelHeader,
    Table,
    TableCell,
    TableColumn,
    TableColumns,
    TableRow,
    TableRows,
} from '@statamic/cms/ui';

const props = defineProps({
    events: { type: Array, default: () => [] },
    recent: { type: Object, default: () => ({}) },
    setup: { type: Object, default: () => ({}) },
    canCheckIn: Boolean,
    checkInUrl: String,
    settingsUrl: { type: String, default: null },
});

const needsSetup = computed(() => !props.setup.stripe || !props.setup.webhookSecret || props.setup.missingCollections?.length);
const upcoming = computed(() => props.events.filter((e) => !e.past));
const past = computed(() => props.events.filter((e) => e.past));

function state(e) {
    if (e.past) return { text: __('Past'), color: 'default' };
    if (e.sold_out) return { text: __('Sold out'), color: 'red' };
    if (e.selling) return { text: __('On sale'), color: 'green' };
    return { text: __('Not on sale'), color: 'default' };
}

function percent(e) {
    return e.capacity ? Math.min(100, Math.round((e.sold / e.capacity) * 100)) : null;
}
</script>

<template>
    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Head :title="__('Tickets')" />

        <Header :title="__('Tickets')" icon="media-ticket">
            <Button v-if="settingsUrl" :href="settingsUrl" icon="cog" :text="__('Settings')" variant="ghost" />
            <Button v-if="canCheckIn" :href="checkInUrl" icon="clipboard-check" :text="__('Check-in')" variant="primary" />
        </Header>

        <Description
            :text="__('Ticket sales for your events. Buyers pay with Stripe and get QR tickets by email; you check them in with a phone.')"
            class="-mt-4 mb-6"
        />

        <Alert
            v-if="setup.stripe && setup.testMode"
            variant="warning"
            class="mb-6"
            :heading="__('Stripe test mode')"
            :text="__('Payments use your Stripe test key, so no real money moves. Pay with the card number 4242 4242 4242 4242 to try it.')"
            data-kt-test-mode
        />

        <Panel v-if="needsSetup" class="mb-6" data-kt-setup>
            <PanelHeader>
                <Heading :text="__('Get started')" />
            </PanelHeader>
            <Card>
                <ol class="list-decimal ps-5 space-y-3 text-sm">
                    <li :class="{ 'opacity-60': setup.stripe }">
                        {{ __('Add your Stripe secret key to .env:') }}
                        <code class="text-xs">KARELIA_TICKETS_STRIPE_SECRET=sk_live_…</code>
                        <Badge v-if="setup.stripe" color="green" :text="__('Done')" class="ms-1" />
                        <div class="text-gray-600 dark:text-gray-400 mt-1">{{ __('Free events work without Stripe.') }}</div>
                    </li>
                    <li :class="{ 'opacity-60': setup.webhookSecret }">
                        {{ __('In Stripe, go to Developers → Webhooks, add this endpoint and choose these events:') }}
                        <Input :model-value="setup.webhookUrl" read-only copyable class="my-2 max-w-xl" />
                        <code class="text-xs">{{ setup.webhookEvents.join(', ') }}</code>
                        <div class="mt-1">
                            {{ __('Then put its signing secret in .env:') }}
                            <code class="text-xs">KARELIA_TICKETS_STRIPE_WEBHOOK_SECRET=whsec_…</code>
                            <Badge v-if="setup.webhookSecret" color="green" :text="__('Done')" class="ms-1" />
                        </div>
                    </li>
                    <li>
                        {{ __('Open an event, go to the Tickets tab, switch on “Sell tickets” and add ticket types.') }}
                        <span v-if="setup.missingCollections?.length" class="text-red-700 dark:text-red-400">
                            {{ __('There is no collection called :handles. Choose your events collection in the settings.', { handles: setup.missingCollections.join(', ') }) }}
                        </span>
                    </li>
                    <li>
                        {{ __('Put') }} <code class="text-xs">&#123;&#123; tickets:form &#125;&#125;</code>
                        {{ __('in the event template.') }}
                    </li>
                </ol>
            </Card>
        </Panel>

        <div class="kt-stats mb-6">
            <Card class="p-4">
                <div class="text-sm text-gray-600 dark:text-gray-400">{{ __('Tickets sold, last :days days', { days: recent.days }) }}</div>
                <div class="text-2xl font-semibold tabular-nums mt-1" data-kt-stat="tickets">{{ recent.tickets }}</div>
            </Card>
            <Card class="p-4">
                <div class="text-sm text-gray-600 dark:text-gray-400">{{ __('Orders') }}</div>
                <div class="text-2xl font-semibold tabular-nums mt-1">{{ recent.orders }}</div>
            </Card>
            <Card class="p-4">
                <div class="text-sm text-gray-600 dark:text-gray-400">{{ __('Revenue') }}</div>
                <div class="text-2xl font-semibold tabular-nums mt-1">{{ recent.revenue }}</div>
            </Card>
        </div>

        <Panel>
            <PanelHeader>
                <Heading :text="__('Events')" />
                <span v-if="setup.lastWebhook" class="text-xs text-gray-600 dark:text-gray-400">
                    {{ __('Last message from Stripe: :at', { at: setup.lastWebhook.at }) }}
                </span>
            </PanelHeader>

            <Card v-if="!events.length" class="p-6 text-center text-sm text-gray-600 dark:text-gray-400">
                {{ __('No events sell tickets yet. Open an entry in :collections and switch on “Sell tickets” in its Tickets tab.', { collections: setup.collections.join(', ') }) }}
            </Card>

            <Table v-else data-kt-events>
                <TableColumns>
                    <TableColumn>{{ __('Event') }}</TableColumn>
                    <TableColumn>{{ __('Sold') }}</TableColumn>
                    <TableColumn class="kt-wide">{{ __('Checked in') }}</TableColumn>
                    <TableColumn class="kt-wide">{{ __('Revenue') }}</TableColumn>
                    <TableColumn>{{ __('Status') }}</TableColumn>
                </TableColumns>
                <TableRows>
                    <TableRow v-for="e in [...upcoming, ...past]" :key="e.id">
                        <TableCell>
                            <Link :href="e.show_url" class="font-medium hover:underline">{{ e.title }}</Link>
                            <div v-if="e.date_label" class="text-xs text-gray-600 dark:text-gray-400">{{ e.date_label }}</div>
                        </TableCell>
                        <TableCell>
                            <div class="tabular-nums text-sm">
                                {{ e.sold }}<span v-if="e.capacity" class="text-gray-500"> / {{ e.capacity }}</span>
                                <span v-if="e.held" class="text-xs text-amber-700 dark:text-amber-400"> (+{{ e.held }} {{ __('in checkout') }})</span>
                            </div>
                            <div v-if="percent(e) !== null" class="h-1.5 w-28 rounded bg-gray-200 dark:bg-gray-700 mt-1 overflow-hidden">
                                <div class="h-full bg-green-600" :style="{ width: percent(e) + '%' }" />
                            </div>
                        </TableCell>
                        <TableCell class="kt-wide tabular-nums text-sm">{{ e.checked_in }}</TableCell>
                        <TableCell class="kt-wide tabular-nums text-sm">{{ e.revenue_formatted }}</TableCell>
                        <TableCell><Badge :color="state(e).color" :text="state(e).text" /></TableCell>
                    </TableRow>
                </TableRows>
            </Table>
        </Panel>
    </div>
</template>
