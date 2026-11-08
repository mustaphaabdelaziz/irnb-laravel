<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { localToday } from '@/lib/localDate';
import { sportLabeller } from '@/lib/sportLabels';

const { t } = useI18n();

const props = defineProps({
    total: Number,
    registrations: Object,
    filters: { type: Object, default: () => ({}) },
    branches: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    years: { type: Array, default: () => [] },
});

const categoryLabel = computed(() => sportLabeller(props.categories, (c) => c.localized_name || c.name));
const dates = reactive({});
const errors = reactive({});
const today = localToday();
const selectClass = 'rounded-lg border-slate-300 text-sm dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500';

const filter = ref({
    branch_id: props.filters.branch_id ?? '',
    category_id: props.filters.category_id ?? '',
    year: props.filters.year ?? '',
});

function applyFilter() {
    router.get(route('players.join-dates.index'), Object.fromEntries(Object.entries(filter.value).filter(([, value]) => value !== '')), { preserveState: true, replace: true });
}

function clearError(row) {
    delete errors[row.id];
}

function save(row) {
    if (!dates[row.id]) return;
    router.put(route('players.join-dates.update', row.id), { join_date: dates[row.id] }, {
        preserveScroll: true,
        onError: (e) => { errors[row.id] = e.join_date; },
        onSuccess: () => { delete errors[row.id]; delete dates[row.id]; },
    });
}
</script>

<template>
    <Head :title="t('join_dates_queue')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('join_dates_queue') }}</h1>
        </template>

        <div class="mx-auto max-w-5xl space-y-4">
            <p class="text-sm text-slate-600 dark:text-slate-300">{{ t('join_dates_queue_intro', { count: total }) }}</p>

            <div class="flex flex-wrap gap-2">
                <select v-model="filter.branch_id" :class="selectClass" :aria-label="t('branch')" @change="applyFilter">
                    <option value="">{{ t('branch') }}</option>
                    <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.localized_name || branch.name }}</option>
                </select>
                <select v-model="filter.category_id" :class="selectClass" :aria-label="t('category')" @change="applyFilter">
                    <option value="">{{ t('category') }}</option>
                    <option v-for="category in categories" :key="category.id" :value="category.id">{{ categoryLabel(category) }}</option>
                </select>
                <select v-model="filter.year" :class="selectClass" :aria-label="t('join_year')" @change="applyFilter">
                    <option value="">{{ t('join_year') }}</option>
                    <option v-for="year in years" :key="year" :value="year">{{ year }}</option>
                </select>
            </div>

            <ul class="divide-y divide-slate-100 rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:divide-slate-800 dark:bg-slate-900 dark:ring-slate-800">
                <li v-for="(row, index) in registrations.data" :key="row.id" :data-help="index === 0 ? 'join-dates-row' : null" class="grid grid-cols-1 gap-2 p-4 sm:grid-cols-2 sm:items-center">
                    <div class="min-w-0">
                        <Link :href="route('players.show', row.player_id)" class="block break-words font-medium text-slate-900 hover:text-primary-600 dark:text-slate-100 dark:hover:text-primary-300">{{ row.player_name }}</Link>
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            {{ row.branch }}<template v-if="row.category"> · {{ row.category }}</template>
                            <template v-if="row.year"> · {{ row.year }}</template>
                        </p>
                        <p v-if="row.two_branches" class="text-xs text-amber-600 dark:text-amber-400">{{ t('join_dates_shared_year') }}</p>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <TextInput v-model="dates[row.id]" type="date" class="min-w-0 flex-1" :max="today" :aria-label="t('join_date')" @change="clearError(row)" @keyup.enter="save(row)" />
                            <button type="button" class="rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50" :disabled="!dates[row.id]" @click="save(row)">{{ t('save') }}</button>
                        </div>
                        <button v-if="row.hint" type="button" class="mt-1 text-xs text-primary-600 hover:underline dark:text-primary-300" @click="dates[row.id] = row.hint; clearError(row)">
                            {{ t('join_dates_hint', { date: row.hint }) }}
                        </button>
                        <p v-if="errors[row.id]" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ errors[row.id] }}</p>
                    </div>
                </li>
                <li v-if="!registrations.data.length" class="p-6 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('join_dates_none') }}</li>
            </ul>

            <div v-if="registrations.prev_page_url || registrations.next_page_url" class="flex justify-between">
                <Link v-if="registrations.prev_page_url" :href="registrations.prev_page_url" class="text-sm text-primary-600 dark:text-primary-300">{{ t('previous') }}</Link>
                <span v-else />
                <Link v-if="registrations.next_page_url" :href="registrations.next_page_url" class="text-sm text-primary-600 dark:text-primary-300">{{ t('next') }}</Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
