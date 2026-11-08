<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MultiSelectFilter from '@/Components/MultiSelectFilter.vue';
import SearchInput from '@/Components/SearchInput.vue';
import Pagination from '@/Components/Pagination.vue';
import StatTile from '@/Components/Dashboard/StatTile.vue';
import Icon from '@/Components/Icon.vue';
import { useCan } from '@/Composables/useCan';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { reactive, watch } from 'vue';

const { t } = useI18n();
const { can } = useCan();

const props = defineProps({
    activities: Object,
    stats: Object,
    filters: Object,
    types: Array,
    branches: Array,
});

const state = reactive({
    types: props.filters.types ?? [],
    branch: props.filters.branch ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    search: props.filters.search ?? '',
});

let timer = null;
watch(state, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('activities.index'), Object.fromEntries(Object.entries(state).filter(([, v]) => v !== '' && !(Array.isArray(v) && !v.length))), {
            preserveState: true, preserveScroll: true, replace: true,
        });
    }, 300);
});

const dates = (a) => {
    const base = a.ends_on && a.ends_on !== a.starts_on ? `${a.starts_on} → ${a.ends_on}` : a.starts_on;
    return a.date_label ? `${a.date_label} (${base})` : base;
};
</script>

<template>
    <Head :title="t('activities')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('activities') }}</h1>
                <Link v-if="can('activities', 'add')" :href="route('activities.create')" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                    <Icon name="plus" class="h-4 w-4" /> {{ t('activities.add') }}
                </Link>
            </div>
        </template>

        <div class="space-y-6">
            <div class="grid gap-4 sm:grid-cols-3">
                <StatTile :label="t('activities.stats.count')" :value="stats.count" icon="heart" />
                <StatTile :label="t('activities.stats.beneficiaries')" :value="stats.beneficiaries" icon="players" />
                <StatTile :label="t('activities.stats.participants')" :value="stats.participants" icon="members" />
            </div>

            <div class="flex flex-wrap items-end gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <SearchInput v-model="state.search" :placeholder="t('activities.search_placeholder')" class="min-w-56 flex-1" />
                <MultiSelectFilter v-model="state.types" :options="types.map((ty) => ({ value: String(ty.id), label: ty.name }))" :label="t('activities.type')" :placeholder="t('activities.all_types')" collapse-all />
                <select v-model="state.branch" class="rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900">
                    <option value="">{{ t('activities.all_branches') }}</option>
                    <option v-for="b in branches" :key="b.id" :value="String(b.id)">{{ b.localized_name }}</option>
                </select>
                <label class="text-sm text-slate-600 dark:text-slate-300">{{ t('activities.from') }}
                    <input v-model="state.from" type="date" class="ms-1 rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900" />
                </label>
                <label class="text-sm text-slate-600 dark:text-slate-300">{{ t('activities.to') }}
                    <input v-model="state.to" type="date" class="ms-1 rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900" />
                </label>
            </div>

            <ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <li v-for="a in activities.data" :key="a.id">
                    <Link :href="route('activities.show', a.id)" class="flex h-full flex-col overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 transition hover:ring-primary-300 dark:bg-slate-900 dark:ring-slate-800">
                        <img v-if="a.cover" :src="a.cover.thumb_url" alt="" loading="lazy" class="h-40 w-full object-cover" />
                        <div v-else class="flex h-40 items-center justify-center bg-slate-50 text-slate-300 dark:bg-slate-950 dark:text-slate-700"><Icon name="heart" class="h-10 w-10" /></div>
                        <div class="flex flex-1 flex-col gap-1 p-4">
                            <span class="w-fit rounded bg-primary-50 px-2 py-0.5 text-xs text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">{{ a.type?.name }}</span>
                            <h2 class="font-semibold text-slate-900 dark:text-slate-100">{{ a.title }}</h2>
                            <p class="text-sm text-slate-500 dark:text-slate-400">{{ dates(a) }}<span v-if="a.location"> · {{ a.location }}</span></p>
                            <p class="mt-auto pt-2 text-xs text-slate-500 dark:text-slate-400">
                                {{ t('activities.beneficiaries') }}: {{ a.beneficiaries_count }} · {{ t('activities.participants') }}: {{ a.participants_count }}
                            </p>
                        </div>
                    </Link>
                </li>
            </ul>
            <p v-if="!activities.data.length" class="rounded-2xl bg-white p-8 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-400 dark:ring-slate-800">{{ t('activities.empty') }}</p>

            <Pagination :links="activities.links" />
        </div>
    </AuthenticatedLayout>
</template>
