<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { Head, Link } from '@statamic/cms/inertia';
import { Button, Card, Description, Header, Input } from '@statamic/cms/ui';

const props = defineProps({
    events: { type: Array, default: () => [] },
    selected: { type: String, default: null },
    urls: { type: Object, default: () => ({}) },
});

const http = () => Statamic.$app.config.globalProperties.$axios;

const event = computed(() => props.events.find((e) => e.id === props.selected) || null);
const counts = ref(event.value ? { sold: event.value.sold, checked_in: event.value.checked_in } : null);
const code = ref('');
const result = ref(null);
const history = ref([]);
const sending = ref(false);
const video = ref(null);
const camera = ref('off'); // off | starting | on | failed
const cameraError = ref('');
let scanner = null;
let lastScan = { code: null, at: 0 };

const tone = {
    ok: { box: 'bg-green-600 text-white', label: __('Checked in') },
    already: { box: 'bg-amber-500 text-black', label: __('Already checked in') },
    wrong_event: { box: 'bg-red-600 text-white', label: __('Wrong event') },
    void: { box: 'bg-red-600 text-white', label: __('Not valid') },
    unknown: { box: 'bg-red-600 text-white', label: __('Unknown ticket') },
};

function feedback(kind) {
    if (navigator.vibrate) navigator.vibrate(kind === 'ok' ? 80 : [60, 60, 60, 60, 160]);
}

function submit(value) {
    const raw = String(value ?? code.value).trim();
    if (!raw || sending.value) return;

    sending.value = true;
    http()
        .post(props.urls.store, { code: raw, event: props.selected })
        .then((r) => {
            result.value = { ...r.data, scanned: raw };
            if (r.data.counts) counts.value = r.data.counts;
            history.value = [result.value, ...history.value].slice(0, 12);
            feedback(r.data.result);
            code.value = '';
        })
        .catch((e) => Statamic.$toast.error(e.response?.data?.message || __('Something went wrong')))
        .finally(() => (sending.value = false));
}

function undo(item) {
    http()
        .post(props.urls.undo, { code: item.ticket.code })
        .then(() => {
            Statamic.$toast.success(__('Check-in undone for :code', { code: item.ticket.code }));
            item.undone = true;
            if (counts.value) counts.value.checked_in = Math.max(0, counts.value.checked_in - 1);
            if (result.value === item) result.value = null;
        })
        .catch(() => Statamic.$toast.error(__('Something went wrong')));
}

async function startCamera() {
    camera.value = 'starting';
    cameraError.value = '';
    await nextTick();

    try {
        const { default: QrScanner } = await import('qr-scanner');
        scanner = new QrScanner(
            video.value,
            (scan) => {
                const value = (scan.data || '').trim();
                const now = Date.now();
                // The same code stays in view for a while: read it once.
                if (!value || (value === lastScan.code && now - lastScan.at < 4000)) return;
                lastScan = { code: value, at: now };
                submit(value);
            },
            { preferredCamera: 'environment', highlightScanRegion: true, highlightCodeOutline: true, maxScansPerSecond: 8, returnDetailedScanResult: true },
        );
        await scanner.start();
        camera.value = 'on';
    } catch (e) {
        camera.value = 'failed';
        cameraError.value = typeof e === 'string' ? e : e?.message || __('The camera could not be started.');
        stopCamera(false);
    }
}

function stopCamera(reset = true) {
    if (scanner) {
        scanner.stop();
        scanner.destroy();
        scanner = null;
    }
    if (reset) camera.value = 'off';
}

onBeforeUnmount(() => stopCamera());
</script>

<template>
    <div class="max-w-2xl mx-auto" data-max-width-wrapper>
        <Head :title="__('Check-in')" />

        <Header :title="event ? event.title : __('Check-in')" icon="clipboard-check">
            <span v-if="counts" class="text-lg font-semibold tabular-nums" data-kt-counts>
                {{ counts.checked_in }} <span class="text-gray-500 font-normal">/ {{ counts.sold }}</span>
            </span>
        </Header>

        <template v-if="!event">
            <Description :text="__('Choose the event you are checking people in for.')" class="-mt-4 mb-6" />
            <Card v-if="!events.length" class="p-6 text-center text-sm text-gray-600 dark:text-gray-400">
                {{ __('No upcoming events with tickets.') }}
            </Card>
            <div class="grid gap-3">
                <Link
                    v-for="e in events"
                    :key="e.id"
                    :href="e.url"
                    class="block rounded-xl border border-gray-200 dark:border-gray-700 p-4 hover:bg-gray-50 dark:hover:bg-gray-800"
                >
                    <div class="font-semibold">{{ e.title }}</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">
                        <span v-if="e.date_label">{{ e.date_label }} · </span>{{ __(':in of :sold checked in', { in: e.checked_in, sold: e.sold }) }}
                    </div>
                </Link>
            </div>
        </template>

        <template v-else>
            <p class="-mt-4 mb-4 text-sm text-gray-600 dark:text-gray-400">
                <span v-if="event.date_label">{{ event.date_label }} · </span>
                <Link :href="urls.index" class="hover:underline">{{ __('Choose another event') }}</Link>
            </p>

            <div
                v-if="result"
                class="rounded-2xl p-5 mb-4 shadow-sm"
                :class="tone[result.result]?.box"
                role="status"
                aria-live="assertive"
                data-kt-result
                :data-result="result.result"
            >
                <div class="text-sm font-semibold uppercase tracking-wide opacity-90">{{ tone[result.result]?.label }}</div>
                <div v-if="result.ticket" class="text-3xl font-bold mt-1 leading-tight">{{ result.ticket.name }}</div>
                <div class="mt-1 text-base">
                    <template v-if="result.ticket">{{ result.ticket.type_name }} · <span class="font-mono tracking-wider">{{ result.ticket.code }}</span></template>
                </div>
                <div class="mt-2 text-base font-medium">
                    <template v-if="result.result === 'already' && result.checked_in_at">{{ __('First checked in at :time.', { time: result.checked_in_at }) }}</template>
                    <template v-else>{{ result.message }}</template>
                </div>
                <button
                    v-if="result.result === 'ok' && !result.undone"
                    type="button"
                    class="mt-3 text-sm underline underline-offset-2 opacity-90"
                    @click="undo(result)"
                >{{ __('Undo') }}</button>
            </div>

            <Card class="p-4 mb-4">
                <div v-show="camera === 'on' || camera === 'starting'" class="kt-video relative rounded-xl overflow-hidden bg-black mb-3">
                    <video ref="video" class="w-full h-full object-cover" muted playsinline />
                </div>
                <div class="flex flex-wrap gap-2 mb-3">
                    <Button v-if="camera !== 'on'" icon="media-image-camera" :text="__('Scan with camera')" variant="primary" :loading="camera === 'starting'" @click="startCamera" />
                    <Button v-else icon="x" :text="__('Stop camera')" @click="stopCamera()" />
                </div>
                <p v-if="camera === 'failed'" class="text-sm text-red-700 dark:text-red-400 mb-3">
                    {{ cameraError }} {{ __('Allow camera access for this site, or type the code below.') }}
                </p>
                <form class="flex gap-2" @submit.prevent="submit()">
                    <Input
                        v-model="code"
                        :placeholder="__('Ticket code')"
                        class="flex-1 font-mono uppercase"
                        autocomplete="off"
                        autocapitalize="characters"
                        spellcheck="false"
                        data-kt-code
                    />
                    <Button type="submit" :text="__('Check in')" variant="primary" :loading="sending" />
                </form>
            </Card>

            <Card v-if="history.length > 1" class="p-4">
                <div class="text-sm font-semibold mb-2">{{ __('Recent') }}</div>
                <ul class="divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <li v-for="(h, i) in history.slice(1)" :key="i" class="py-2 flex justify-between gap-3">
                        <span class="truncate">{{ h.ticket?.name || h.scanned }}</span>
                        <span :class="{ 'text-green-700 dark:text-green-400': h.result === 'ok' && !h.undone, 'text-amber-700 dark:text-amber-400': h.result === 'already', 'text-red-700 dark:text-red-400': !['ok', 'already'].includes(h.result) }">
                            {{ h.undone ? __('Undone') : tone[h.result]?.label }}
                        </span>
                    </li>
                </ul>
            </Card>
        </template>
    </div>
</template>
