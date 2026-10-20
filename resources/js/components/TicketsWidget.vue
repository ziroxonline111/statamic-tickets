<script setup>
import { Widget } from '@statamic/cms/ui';
import { Link } from '@statamic/cms/inertia';

defineProps({
    title: String,
    events: { type: Array, default: () => [] },
    recent: { type: Object, default: () => ({}) },
    url: String,
});

function percent(e) {
    return e.capacity ? Math.min(100, Math.round((e.sold / e.capacity) * 100)) : null;
}
</script>

<template>
    <Widget :title="title" icon="media-ticket" :href="url">
        <div class="px-4 py-3">
            <div class="flex items-baseline justify-between">
                <span class="text-sm text-gray-600 dark:text-gray-400">{{ __('Sold in the last :days days', { days: recent.days }) }}</span>
                <span class="text-2xl font-semibold tabular-nums">{{ recent.tickets }}</span>
            </div>
            <ul v-if="events.length" class="mt-3 space-y-3 text-sm">
                <li v-for="e in events" :key="e.url">
                    <Link :href="e.url" class="flex justify-between gap-3 hover:underline">
                        <span class="truncate font-medium">{{ e.title }}</span>
                        <span class="tabular-nums text-gray-600 dark:text-gray-400 shrink-0">{{ e.sold }}<template v-if="e.capacity"> / {{ e.capacity }}</template></span>
                    </Link>
                    <div v-if="e.date_label" class="text-xs text-gray-600 dark:text-gray-400">{{ e.date_label }}</div>
                    <div v-if="percent(e) !== null" class="h-1.5 rounded bg-gray-200 dark:bg-gray-700 mt-1 overflow-hidden">
                        <div class="h-full bg-green-600" :style="{ width: percent(e) + '%' }" />
                    </div>
                </li>
            </ul>
            <p v-else class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('No upcoming events with tickets.') }}</p>
        </div>
        <template #footer>
            <Link :href="url" class="text-sm text-blue-600 dark:text-blue-400 hover:underline px-4 py-2 block">
                {{ __('Open Tickets') }}
            </Link>
        </template>
    </Widget>
</template>
