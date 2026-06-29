<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Badge from '@/Components/Badge.vue';
import Icon from '@/Components/Icon.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    item: Object,
    history: Array,
});

const eventColor = (type) => {
    const map = {
        purchase: 'emerald',
        rental: 'amber',
        return: 'primary',
        repair: 'slate',
        lost: 'rose',
        condition_update: 'slate',
    };
    return map[type] || 'slate';
};

const eventIcon = (type) => {
    const map = {
        purchase: 'money',
        rental: 'upload',
        return: 'download',
        repair: 'wrench',
        lost: 'xcircle',
        condition_update: 'document',
    };
    return map[type] || 'document';
};
</script>

<template>
    <Head :title="item.unique_identifier" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="item.catalog ? route('equipment.catalogs.show', item.catalog.id) : route('equipment.catalogs.index')" class="text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
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
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('status') }}</p>
                        <div class="mt-1"><Badge :label="item.status" :color="item.status === 'Available' ? 'emerald' : item.status === 'Rented' ? 'amber' : 'rose'" /></div>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('condition') }}</p>
                        <p class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ item.condition }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">{{ t('location') }}</p>
                        <p class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ item.location || '-' }}</p>
                    </div>
                </div>
                <div v-if="item.rented_to" class="mt-4 rounded-lg bg-amber-50 p-3 text-sm dark:bg-amber-500/10">
                    <span class="font-medium text-amber-900 dark:text-amber-200">{{ t('rented') }}:</span>
                    <Link :href="route('players.show', item.rented_to.id)" class="ms-1 text-primary-600 dark:text-primary-400 hover:underline">
                        {{ item.rented_to.firstname }} {{ item.rented_to.lastname }}
                    </Link>
                    <span v-if="item.due_date" class="ms-2 text-amber-700 dark:text-amber-300"> — {{ t('due_date') }}: {{ item.due_date }}</span>
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
                            :class="{
                                'text-emerald-600 ring-emerald-400': event.event_type === 'purchase',
                                'text-amber-600 ring-amber-400': event.event_type === 'rental',
                                'text-primary-600 ring-primary-400': event.event_type === 'return',
                                'text-slate-500 dark:text-slate-400 ring-slate-400': !['purchase','rental','return'].includes(event.event_type),
                            }">
                            <Icon :name="eventIcon(event.event_type)" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <Badge :label="event.event_type" :color="eventColor(event.event_type)" />
                                <span class="text-xs text-slate-500 dark:text-slate-400">{{ event.created_at }}</span>
                            </div>
                            <div v-if="event.details" class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                                <p v-if="event.details.vendor">Vendor: {{ event.details.vendor }}</p>
                                <p v-if="event.details.price">{{ t('price') }}: {{ event.details.price }}</p>
                                <p v-if="event.details.player">{{ t('player') }}: {{ event.details.player }}</p>
                                <p v-if="event.details.condition">{{ t('condition') }}: {{ event.details.condition }}</p>
                                <p v-if="event.details.notes">{{ event.details.notes }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
