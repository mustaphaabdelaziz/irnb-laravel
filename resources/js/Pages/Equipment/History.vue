<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Badge from '@/Components/Badge.vue';
import Icon from '@/Components/Icon.vue';
import RentalTypeBadge from '@/Components/RentalTypeBadge.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    item: Object,
    history: Array,
});

// Summary computed from the event log. Rentals cover both loans (checkout)
// and assignments; repairs count send-to-repair events.
const stats = computed(() => {
    const h = props.history ?? [];
    const is = (...types) => h.filter((e) => types.includes(e.event_type)).length;
    const recipients = new Set(
        h.map((e) => e.details?.recipient).filter(Boolean),
    );
    return {
        events: h.length,
        rentals: is('checkout', 'rental', 'assigned'),
        returns: is('return'),
        repairs: is('repair'),
        people: recipients.size,
    };
});

// Event types are stored capitalised, sometimes with spaces ("Split Out"),
// so normalise to a lowercase_underscore key before any lookup. Without this
// every map missed and all events rendered grey with the default icon.
const normalizeEvent = (type) => String(type ?? '').toLowerCase().replaceAll(' ', '_');

const EVENT_COLORS = {
    received: 'emerald',
    purchase: 'emerald',
    checkout: 'amber',
    rental: 'amber',
    assigned: 'amber',
    return: 'primary',
    repair: 'slate',
    repair_complete: 'emerald',
    lost: 'rose',
    found: 'emerald',
    retired: 'slate',
    split_out: 'amber',
    split_in: 'emerald',
};

const EVENT_ICONS = {
    received: 'money',
    purchase: 'money',
    checkout: 'upload',
    rental: 'upload',
    assigned: 'upload',
    return: 'download',
    repair: 'wrench',
    repair_complete: 'wrench',
    lost: 'xcircle',
    found: 'download',
    retired: 'xcircle',
    split_out: 'document',
    split_in: 'document',
};

const eventColor = (type) => EVENT_COLORS[normalizeEvent(type)] || 'slate';
const eventIcon = (type) => EVENT_ICONS[normalizeEvent(type)] || 'document';

// Timeline dot text + ring, derived from the same colour map so the marker,
// the badge and the icon always agree. Each colour needs its dark variant.
const DOT_CLASSES = {
    emerald: 'text-emerald-600 dark:text-emerald-400 ring-emerald-400 dark:ring-emerald-500',
    amber: 'text-amber-600 dark:text-amber-400 ring-amber-400 dark:ring-amber-500',
    primary: 'text-primary-600 dark:text-primary-400 ring-primary-400 dark:ring-primary-500',
    rose: 'text-rose-600 dark:text-rose-400 ring-rose-400 dark:ring-rose-500',
    slate: 'text-slate-500 dark:text-slate-400 ring-slate-400 dark:ring-slate-600',
};
const dotClass = (type) => DOT_CLASSES[eventColor(type)] || DOT_CLASSES.slate;

// Translate an enum value (status/condition) via its lowercased, underscored key.
const stateLabel = (v) => {
    if (!v) return '—';
    const key = String(v).toLowerCase().replaceAll(' ', '_');
    const translated = t(key);
    return translated === key ? v : translated;
};

// Translate the normalized event type; fall back to readable text if unmapped.
const eventLabel = (type) => {
    const key = 'event_' + normalizeEvent(type);
    const translated = t(key);
    return translated === key ? String(type ?? '').replaceAll('_', ' ') : translated;
};
</script>

<template>
    <Head :title="item.unique_identifier" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="item.catalog ? route('equipment.catalogs.show', item.catalog.id) : route('equipment.catalogs.index')" class="text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300">
                    <Icon name="back" class="text-lg rtl:rotate-180" />
                </Link>
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ item.catalog?.name || item.unique_identifier }}</h1>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Item info card -->
            <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('identifier') }}</p>
                        <p class="mt-1 font-mono text-sm text-slate-900 dark:text-slate-100">{{ item.unique_identifier }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('designation') }}</p>
                        <p class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ item.designation || '-' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('status') }}</p>
                        <div class="mt-1"><Badge :label="stateLabel(item.status)" :color="item.status === 'Available' ? 'emerald' : item.status === 'Rented' ? 'amber' : 'rose'" /></div>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('condition') }}</p>
                        <p class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ stateLabel(item.condition) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('location') }}</p>
                        <p class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ item.location || '-' }}</p>
                    </div>
                </div>
                <div v-if="item.rented_to" class="mt-4 rounded-lg bg-amber-50 p-3 text-sm dark:bg-amber-500/10">
                    <RentalTypeBadge :type="item.rented_to.type" class="me-1" />
                    <span class="font-medium text-amber-900 dark:text-amber-200">{{ item.rented_to.type === 'assignment' ? t('assigned_to') : t('rented_to') }}:</span>
                    <!-- A player links to their profile; an external person is plain text + phone. -->
                    <Link v-if="item.rented_to.player_id" :href="route('players.show', item.rented_to.player_id)" class="ms-1 text-primary-600 dark:text-primary-400 hover:underline">
                        {{ item.rented_to.name }}
                    </Link>
                    <span v-else class="ms-1 text-amber-900 dark:text-amber-200">
                        {{ item.rented_to.name }}
                        <span v-if="item.rented_to.phone" class="text-amber-700 dark:text-amber-300">({{ item.rented_to.phone }})</span>
                    </span>
                    <span v-if="item.due_date" class="ms-2 text-amber-700 dark:text-amber-300"> — {{ t('return_date') }}: {{ item.due_date }}</span>
                </div>
            </div>

            <!-- Statistics -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                <div v-for="s in [
                    { label: t('total'), value: stats.events, color: 'text-slate-900 dark:text-slate-100' },
                    { label: t('equipment.rental'), value: stats.rentals, color: 'text-amber-600 dark:text-amber-400' },
                    { label: t('return'), value: stats.returns, color: 'text-primary-600 dark:text-primary-400' },
                    { label: t('send_to_repair'), value: stats.repairs, color: 'text-slate-600 dark:text-slate-300' },
                    { label: t('rented_to'), value: stats.people, color: 'text-emerald-600 dark:text-emerald-400' },
                ]" :key="s.label" class="rounded-2xl bg-white dark:bg-slate-900 p-4 text-center shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                    <p class="text-2xl font-bold" :class="s.color">{{ s.value }}</p>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ s.label }}</p>
                </div>
            </div>

            <!-- History timeline -->
            <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <h3 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('history') }}</h3>
                <div v-if="!history?.length" class="py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</div>
                <div v-else class="relative space-y-0">
                    <div class="absolute start-4 top-2 h-[calc(100%-1rem)] w-0.5 bg-slate-200 dark:bg-slate-800" />
                    <div v-for="(event, idx) in history" :key="event.id || idx" class="relative flex gap-4 pb-6">
                        <div class="relative z-10 flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-white dark:bg-slate-900 text-sm ring-2"
                            :class="dotClass(event.event_type)">
                            <Icon :name="eventIcon(event.event_type)" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <Badge :label="eventLabel(event.event_type)" :color="eventColor(event.event_type)" />
                                <span class="text-xs text-slate-500 dark:text-slate-400">{{ event.created_at }}</span>
                            </div>
                            <!-- Keys match what the lifecycle/stock services actually write. -->
                            <div v-if="event.details" class="mt-1 space-y-0.5 text-sm text-slate-600 dark:text-slate-300">
                                <p v-if="event.details.recipient" class="font-medium text-slate-700 dark:text-slate-200">{{ t('rented_to') }}: {{ event.details.recipient }}</p>
                                <p v-if="event.details.quantity">{{ t('quantity') }}: {{ event.details.quantity }}</p>
                                <p v-if="event.details.unit_price">{{ t('unit_price') }}: {{ event.details.unit_price }}</p>
                                <p v-if="event.details.received_via">{{ t('equipment.received_via') }}: {{ t(event.details.received_via) }}</p>
                                <p v-if="event.details.due_date">{{ t('return_date') }}: {{ event.details.due_date }}</p>
                                <p v-if="event.details.returned_condition">{{ t('condition') }}: {{ stateLabel(event.details.returned_condition) }}</p>
                                <p v-if="event.details.condition">{{ t('condition') }}: {{ stateLabel(event.details.condition) }}</p>
                                <p v-if="event.details.previous_status">{{ t('status') }}: {{ stateLabel(event.details.previous_status) }}</p>
                                <p v-if="event.details.notes">{{ event.details.notes }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
