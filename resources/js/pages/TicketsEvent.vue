<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@statamic/cms/inertia';
import {
    Badge,
    Button,
    Card,
    Dropdown,
    DropdownItem,
    DropdownMenu,
    DropdownSeparator,
    Field,
    Header,
    Heading,
    Input,
    Modal,
    Panel,
    PanelHeader,
    Select,
    Switch,
    Table,
    TableCell,
    TableColumn,
    TableColumns,
    TableRow,
    TableRows,
} from '@statamic/cms/ui';

const props = defineProps({
    event: { type: Object, required: true },
    types: { type: Array, default: () => [] },
    orders: { type: Array, default: () => [] },
    attendees: { type: Array, default: () => [] },
    canManage: Boolean,
    canCheckIn: Boolean,
    urls: { type: Object, default: () => ({}) },
    stripeReady: Boolean,
});

const http = () => Statamic.$app.config.globalProperties.$axios;

const list = ref([...props.orders]);
const tab = ref('orders');
const showAll = ref(false);
const search = ref('');
const busy = ref(null);
const confirming = ref(null);
const compOpen = ref(false);
const comp = ref({ type: props.types[0]?.id ?? null, quantity: 1, name: '', email: '', send: true });
const compErrors = ref({});

const shownOrders = computed(() => {
    if (!showAll.value) return list.value.filter((o) => ['paid', 'refunded', 'cancelled', 'awaiting'].includes(o.status));
    return list.value;
});

const shownAttendees = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.attendees;
    return props.attendees.filter((a) => [a.name, a.email, a.code, a.type, a.order].some((v) => String(v || '').toLowerCase().includes(q)));
});

const statusBadge = {
    paid: { color: 'green', text: __('Paid') },
    awaiting: { color: 'amber', text: __('Payment on its way') },
    pending: { color: 'amber', text: __('In checkout') },
    expired: { color: 'default', text: __('Not completed') },
    cancelled: { color: 'default', text: __('Cancelled') },
    refunded: { color: 'red', text: __('Refunded') },
};

function replace(order) {
    const i = list.value.findIndex((o) => o.id === order.id);
    if (i >= 0) list.value[i] = order;
    else list.value.unshift(order);
}

function post(url, data, key) {
    busy.value = key;
    return http()
        .post(url, data)
        .then((r) => {
            if (r.data.order) replace(r.data.order);
            Statamic.$toast.success(r.data.message);
            return r;
        })
        .catch((e) => {
            Statamic.$toast.error(e.response?.data?.message || __('Something went wrong'));
            throw e;
        })
        .finally(() => (busy.value = null));
}

function confirm(order, action) {
    confirming.value = { order, action };
}

function runConfirmed() {
    const { order, action } = confirming.value;
    const url = action === 'refund' ? order.refund_url : order.cancel_url;
    post(url, {}, order.id + action).finally(() => (confirming.value = null)).catch(() => {});
}

function resend(order) {
    post(order.resend_url, {}, order.id + 'resend').catch(() => {});
}

function addComplimentary() {
    compErrors.value = {};
    post(props.urls.complimentary, comp.value, 'comp')
        .then(() => {
            compOpen.value = false;
            comp.value = { ...comp.value, quantity: 1, name: '', email: '' };
            setTimeout(() => window.location.reload(), 600);
        })
        .catch((e) => (compErrors.value = e.response?.data?.errors || {}));
}

function percent(n, of) {
    return of ? Math.min(100, Math.round((n / of) * 100)) : null;
}
</script>

<template>
    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Head :title="event.title" />

        <div class="mb-2 text-sm">
            <Link :href="urls.index" class="text-gray-600 dark:text-gray-400 hover:underline">← {{ __('Tickets') }}</Link>
        </div>

        <Header :title="event.title" icon="media-ticket">
            <Button v-if="event.edit_url" :href="event.edit_url" icon="pencil" :text="__('Edit event')" variant="ghost" />
            <Button :href="urls.export" icon="download" :text="__('Attendee list (CSV)')" variant="ghost" />
            <Button v-if="canManage" icon="plus" :text="__('Add free tickets')" @click="compOpen = true" />
            <Button v-if="canCheckIn" :href="urls.checkIn" icon="clipboard-check" :text="__('Check-in')" variant="primary" />
        </Header>

        <p v-if="event.date_label" class="-mt-4 mb-6 text-sm text-gray-600 dark:text-gray-400">{{ event.date_label }}</p>

        <div class="kt-stats-4 mb-6" data-kt-event-stats>
            <Card class="p-4">
                <div class="text-sm text-gray-600 dark:text-gray-400">{{ __('Tickets sold') }}</div>
                <div class="text-2xl font-semibold tabular-nums mt-1">
                    {{ event.sold }}<span v-if="event.capacity" class="text-base text-gray-500"> / {{ event.capacity }}</span>
                </div>
                <div v-if="event.held" class="text-xs text-amber-700 dark:text-amber-400">+{{ event.held }} {{ __('in checkout') }}</div>
            </Card>
            <Card class="p-4">
                <div class="text-sm text-gray-600 dark:text-gray-400">{{ __('Checked in') }}</div>
                <div class="text-2xl font-semibold tabular-nums mt-1">{{ event.checked_in }}</div>
            </Card>
            <Card class="p-4">
                <div class="text-sm text-gray-600 dark:text-gray-400">{{ __('Revenue') }}</div>
                <div class="text-2xl font-semibold tabular-nums mt-1">{{ event.revenue_formatted }}</div>
            </Card>
            <Card class="p-4">
                <div class="text-sm text-gray-600 dark:text-gray-400">{{ __('Orders') }}</div>
                <div class="text-2xl font-semibold tabular-nums mt-1">{{ event.orders }}</div>
            </Card>
        </div>

        <Panel class="mb-6">
            <PanelHeader><Heading :text="__('Ticket types')" /></PanelHeader>
            <Card v-if="!types.length" class="p-4 text-sm text-gray-600 dark:text-gray-400">
                {{ __('No ticket types yet. Add them in the event’s Tickets tab.') }}
            </Card>
            <Table v-else>
                <TableColumns>
                    <TableColumn>{{ __('Type') }}</TableColumn>
                    <TableColumn>{{ __('Price') }}</TableColumn>
                    <TableColumn>{{ __('Sold') }}</TableColumn>
                    <TableColumn>{{ __('Left') }}</TableColumn>
                </TableColumns>
                <TableRows>
                    <TableRow v-for="t in types" :key="t.id">
                        <TableCell>
                            <span class="font-medium">{{ t.name }}</span>
                            <Badge v-if="!t.on_sale" :text="__('Sales ended')" class="ms-2" />
                        </TableCell>
                        <TableCell class="tabular-nums">{{ t.price }}</TableCell>
                        <TableCell class="tabular-nums">
                            {{ t.sold }}<span v-if="t.capacity !== null" class="text-gray-500"> / {{ t.capacity }}</span>
                            <div v-if="t.capacity" class="h-1.5 w-24 rounded bg-gray-200 dark:bg-gray-700 mt-1 overflow-hidden">
                                <div class="h-full bg-green-600" :style="{ width: percent(t.sold, t.capacity) + '%' }" />
                            </div>
                        </TableCell>
                        <TableCell class="tabular-nums">
                            <span v-if="t.left === null" class="text-gray-500">{{ __('No limit') }}</span>
                            <span v-else-if="t.left === 0" class="text-red-700 dark:text-red-400 font-medium">{{ __('Sold out') }}</span>
                            <span v-else>{{ t.left }}</span>
                        </TableCell>
                    </TableRow>
                </TableRows>
            </Table>
        </Panel>

        <Panel>
            <div class="flex flex-wrap items-center justify-between gap-3 px-3 pt-2 pb-3">
                <div class="flex gap-1" role="tablist">
                    <Button :variant="tab === 'orders' ? 'primary' : 'ghost'" size="sm" :text="__('Orders')" role="tab" :aria-selected="tab === 'orders'" @click="tab = 'orders'" />
                    <Button :variant="tab === 'attendees' ? 'primary' : 'ghost'" size="sm" :text="__('Attendees') + ' (' + attendees.length + ')'" role="tab" :aria-selected="tab === 'attendees'" @click="tab = 'attendees'" />
                </div>
                <label v-if="tab === 'orders'" class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input v-model="showAll" type="checkbox" class="rounded" />
                    {{ __('Show unfinished orders') }}
                </label>
                <Input v-else v-model="search" :placeholder="__('Search name, email or code')" icon="magnifying-glass" size="sm" class="w-64 max-w-full" />
            </div>

            <template v-if="tab === 'orders'">
                <Card v-if="!shownOrders.length" class="p-4 text-sm text-gray-600 dark:text-gray-400">{{ __('No orders yet.') }}</Card>
                <Table v-else data-kt-orders>
                    <TableColumns>
                        <TableColumn>{{ __('Order') }}</TableColumn>
                        <TableColumn>{{ __('Buyer') }}</TableColumn>
                        <TableColumn class="kt-wide">{{ __('Tickets') }}</TableColumn>
                        <TableColumn>{{ __('Total') }}</TableColumn>
                        <TableColumn>{{ __('Status') }}</TableColumn>
                        <TableColumn />
                    </TableColumns>
                    <TableRows>
                        <TableRow v-for="o in shownOrders" :key="o.id" :data-order="o.number">
                            <TableCell>
                                <span class="font-medium tabular-nums">#{{ o.number }}</span>
                                <div class="text-xs text-gray-600 dark:text-gray-400">{{ o.created }}</div>
                            </TableCell>
                            <TableCell>
                                {{ o.name }}
                                <div class="text-xs text-gray-600 dark:text-gray-400">{{ o.email }}</div>
                            </TableCell>
                            <TableCell class="kt-wide text-sm">{{ o.lines }}</TableCell>
                            <TableCell class="tabular-nums">{{ o.total }}</TableCell>
                            <TableCell>
                                <div class="flex flex-wrap gap-1">
                                    <Badge :color="statusBadge[o.status]?.color" :text="statusBadge[o.status]?.text || o.status" />
                                    <Badge v-if="o.comp" color="blue" :text="__('Free ticket')" />
                                    <Badge v-if="o.partly_refunded" color="amber" :text="__('Partly refunded')" />
                                    <Badge v-if="o.late_payment" color="amber" :text="__('Paid late, check capacity')" />
                                    <Badge v-if="o.mail_error" color="red" :text="__('Email failed')" :title="o.mail_error" />
                                </div>
                            </TableCell>
                            <TableCell class="text-end">
                                <Dropdown align="end">
                                    <template #trigger>
                                        <Button icon="dots" variant="ghost" size="sm" :aria-label="__('Actions for order :number', { number: o.number })" :loading="busy && busy.startsWith(o.id)" />
                                    </template>
                                    <DropdownMenu>
                                        <DropdownItem v-if="o.status === 'paid'" :href="o.ticket_page" target="_blank" icon="external-link" :text="__('Open ticket page')" />
                                        <DropdownItem v-if="canManage && o.can_resend" icon="mail" :text="__('Email tickets again')" @click="resend(o)" />
                                        <DropdownItem v-if="o.stripe_url" :href="o.stripe_url" target="_blank" icon="external-link" :text="__('View in Stripe')" />
                                        <template v-if="canManage && (o.can_refund || o.can_cancel)">
                                            <DropdownSeparator />
                                            <DropdownItem v-if="o.can_refund" icon="arrow-left" variant="destructive" :text="__('Refund order')" @click="confirm(o, 'refund')" />
                                            <DropdownItem v-if="o.can_cancel" icon="x" variant="destructive" :text="__('Cancel tickets')" @click="confirm(o, 'cancel')" />
                                        </template>
                                    </DropdownMenu>
                                </Dropdown>
                            </TableCell>
                        </TableRow>
                    </TableRows>
                </Table>
            </template>

            <template v-else>
                <Card v-if="!shownAttendees.length" class="p-4 text-sm text-gray-600 dark:text-gray-400">{{ __('No attendees found.') }}</Card>
                <Table v-else data-kt-attendees>
                    <TableColumns>
                        <TableColumn>{{ __('Name') }}</TableColumn>
                        <TableColumn>{{ __('Ticket') }}</TableColumn>
                        <TableColumn class="kt-wide">{{ __('Code') }}</TableColumn>
                        <TableColumn>{{ __('Checked in') }}</TableColumn>
                    </TableColumns>
                    <TableRows>
                        <TableRow v-for="a in shownAttendees" :key="a.code">
                            <TableCell>
                                {{ a.name }}
                                <div class="text-xs text-gray-600 dark:text-gray-400">#{{ a.order }} · {{ a.email }}</div>
                            </TableCell>
                            <TableCell>{{ a.type }}</TableCell>
                            <TableCell class="kt-wide font-mono tracking-wider">{{ a.code }}</TableCell>
                            <TableCell>
                                <Badge v-if="a.checked_in_at" color="green" :text="a.checked_in_at" />
                                <span v-else class="text-gray-500">—</span>
                            </TableCell>
                        </TableRow>
                    </TableRows>
                </Table>
            </template>
        </Panel>

        <Modal
            :open="!!confirming"
            :title="confirming?.action === 'refund' ? __('Refund order #:number?', { number: confirming?.order.number }) : __('Cancel order #:number?', { number: confirming?.order.number })"
            @update:open="(v) => !v && (confirming = null)"
        >
            <p v-if="confirming?.action === 'refund'" class="text-sm">
                {{ __(':total goes back to :name through Stripe, and their :count tickets stop working. This cannot be undone.', { total: confirming.order.total, name: confirming.order.name, count: confirming.order.tickets }) }}
            </p>
            <p v-else-if="confirming" class="text-sm">
                {{ __('The :count tickets of :name stop working. This cannot be undone.', { count: confirming.order.tickets, name: confirming.order.name }) }}
            </p>
            <div class="flex justify-end gap-2 mt-6">
                <Button :text="__('Keep order')" variant="ghost" @click="confirming = null" />
                <Button
                    :text="confirming?.action === 'refund' ? __('Refund') : __('Cancel tickets')"
                    variant="danger"
                    :loading="!!busy"
                    data-kt-confirm
                    @click="runConfirmed"
                />
            </div>
        </Modal>

        <Modal :open="compOpen" :title="__('Add free tickets')" @update:open="(v) => (compOpen = v)">
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">{{ __('For guests, press or staff. They count towards the number of tickets available.') }}</p>
            <div class="grid gap-4">
                <Field :label="__('Ticket type')" :error="compErrors.type?.[0]">
                    <Select v-model="comp.type" :options="types.map((t) => ({ label: t.name, value: t.id }))" />
                </Field>
                <Field :label="__('How many')" :error="compErrors.quantity?.[0]">
                    <Input v-model="comp.quantity" type="number" min="1" max="100" />
                </Field>
                <Field :label="__('Name on the tickets')" :error="compErrors.name?.[0]">
                    <Input v-model="comp.name" />
                </Field>
                <Field :label="__('Email')" :instructions="__('Optional. Needed to send the tickets.')" :error="compErrors.email?.[0]">
                    <Input v-model="comp.email" type="email" />
                </Field>
                <Switch v-model="comp.send" :label="__('Email the tickets')" :disabled="!comp.email" />
            </div>
            <div class="flex justify-end gap-2 mt-6">
                <Button :text="__('Cancel')" variant="ghost" @click="compOpen = false" />
                <Button :text="__('Add tickets')" variant="primary" :loading="busy === 'comp'" @click="addComplimentary" />
            </div>
        </Modal>
    </div>
</template>
