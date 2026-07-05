<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import SearchInput from '@/Components/SearchInput.vue';
import Badge from '@/Components/Badge.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref, watch, computed } from 'vue';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import StatDoughnut from '@/Components/StatDoughnut.vue';

const { t } = useI18n();
const { formatMoney } = useFormatMoney();

const props = defineProps({
    players: Object,
    categories: Array,
    categoryStats: { type: Array, default: () => [] },
    statusStats: { type: Array, default: () => [] },
    positionStats: { type: Array, default: () => [] },
    ageStats: { type: Array, default: () => [] },
    filters: Object,
});

const search = ref(props.filters?.search || '');
// The backend filters on `category_id` — the param must match or the filter is a no-op.
const categoryFilter = ref(props.filters?.category_id || '');

const statusFilter = ref(props.filters?.status || '');
const positionFilter = ref(props.filters?.position_id || '');
const ageFilter = ref(props.filters?.age || '');

function applyFilters() {
    router.get(route('players.index'), {
        search: search.value || undefined,
        category_id: categoryFilter.value || undefined,
        status: statusFilter.value || undefined,
        position_id: positionFilter.value || undefined,
        age: ageFilter.value || undefined,
    }, { preserveState: true, replace: true });
}

watch([search, categoryFilter, statusFilter, positionFilter, ageFilter], applyFilters);

// Distribution panels: map each stat source to StatDoughnut's {key, label, count}.
const categoryChips = computed(() => props.categoryStats.map((s) => ({
    key: s.category_id ?? '', label: s.name || t('uncategorized'), count: s.count,
})));
const statusChips = computed(() => props.statusStats.map((s) => ({
    key: s.status ?? '', label: s.status || t('uncategorized'), count: s.count,
})));
const positionChips = computed(() => props.positionStats.map((s) => ({
    key: s.position_id ?? '', label: s.name || t('unassigned'), count: s.count,
})));
const ageLabel = (bucket) => (bucket === 'u10' ? '< 10' : bucket === 'unknown' ? t('unknown') : bucket);
const ageChips = computed(() => props.ageStats.map((s) => ({
    key: s.bucket, label: ageLabel(s.bucket), count: s.count,
})));

const statusPalette = ['#0284c7', '#d97706', '#e11d48', '#64748b', '#7c3aed', '#02a85c'];
const agePalette = ['#7c3aed', '#0284c7', '#02a85c', '#d97706', '#e11d48', '#64748b'];

// List/grid view toggle, remembered across visits.
const view = ref(localStorage.getItem('players.view') || 'list');
watch(view, (v) => localStorage.setItem('players.view', v));

const showImport = ref(false);
const importForm = useForm({ file: null });

function submitImport() {
    importForm.post(route('players.import.store'), {
        forceFormData: true,
        onSuccess: () => { showImport.value = false; importForm.reset(); },
    });
}
</script>

<template>
    <Head :title="t('players')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('players') }}</h1>
                <div class="flex items-center gap-2">
                    <a :href="route('players.import.template')" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2 text-sm font-medium text-slate-700 dark:text-slate-200 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        {{ t('template') }}
                    </a>
                    <button @click="showImport = true" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2 text-sm font-medium text-slate-700 dark:text-slate-200 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v12m0-12l-4 4m4-4l4 4M4 20h16"/></svg>
                        {{ t('import') }}
                    </button>
                    <Link :href="route('players.create')" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700 transition-colors">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        {{ t('new_player') }}
                    </Link>
                </div>
            </div>
        </template>

        <div class="space-y-4">
            <!-- Distribution doughnuts (count + % of active players); click a slice or chip to filter -->
            <div class="grid gap-4 lg:grid-cols-2">
                <StatDoughnut v-if="categoryChips.length" v-model="categoryFilter" :title="t('by_category')" :stats="categoryChips" />
                <StatDoughnut v-if="statusChips.length" v-model="statusFilter" :title="t('by_status')" :stats="statusChips" :palette="statusPalette" />
                <StatDoughnut v-if="positionChips.length" v-model="positionFilter" :title="t('by_position')" :stats="positionChips" />
                <StatDoughnut v-if="ageChips.length" v-model="ageFilter" :title="t('by_age')" :stats="ageChips" :palette="agePalette" />
            </div>

            <!-- Filters + view toggle -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="w-full sm:w-64">
                    <SearchInput v-model="search" :placeholder="t('search_for_member')" />
                </div>
                <select
                    v-model="categoryFilter"
                    class="rounded-lg border-slate-300 dark:border-slate-700 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500"
                >
                    <option value="">{{ t('all_categories') }}</option>
                    <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                </select>
                <div class="ms-auto inline-flex rounded-xl bg-slate-100 p-0.5 dark:bg-slate-800">
                    <button @click="view = 'list'" :title="t('list_view')" class="rounded-lg px-3 py-1.5 text-xs font-bold transition-colors"
                        :class="view === 'list' ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'">☰ {{ t('list_view') }}</button>
                    <button @click="view = 'grid'" :title="t('grid_view')" class="rounded-lg px-3 py-1.5 text-xs font-bold transition-colors"
                        :class="view === 'grid' ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'">▦ {{ t('grid_view') }}</button>
                </div>
            </div>

            <!-- Grid view -->
            <div v-if="view === 'grid'" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    <Link v-for="player in players.data" :key="player.id" :href="route('players.show', player.id)"
                        class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 transition-shadow hover:shadow-md dark:bg-slate-900 dark:ring-slate-800">
                        <div class="flex items-center gap-3">
                            <img v-if="player.picture_url" :src="player.picture_url" :alt="player.firstname" class="h-14 w-14 shrink-0 rounded-xl object-cover ring-1 ring-slate-200 dark:ring-slate-700" />
                            <div v-else class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-xl font-bold text-primary-600 ring-1 ring-primary-100 dark:bg-primary-500/10 dark:text-primary-300">
                                {{ (player.firstname || '?').charAt(0).toUpperCase() }}
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-slate-900 dark:text-slate-100">{{ player.fullname || `${player.lastname} ${player.firstname}` }}</p>
                                <p class="truncate font-mono text-xs text-slate-400">{{ player.membership_id }}</p>
                                <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ player.category?.name || '-' }}</p>
                            </div>
                        </div>
                        <div class="mt-3 flex items-center justify-between border-t border-slate-100 pt-2 text-xs dark:border-slate-800">
                            <Badge v-if="player.archived" :label="t('archived')" color="slate" />
                            <Badge v-else :label="t('active')" color="emerald" />
                            <span class="font-semibold" :class="player.total_debt > 0 ? 'text-rose-700' : 'text-emerald-700'">{{ formatMoney(player.total_debt || 0) }}</span>
                        </div>
                    </Link>
                </div>
                <p v-if="!players.data.length" class="py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</p>
                <Pagination :links="players" />
            </div>

            <!-- Table -->
            <div v-if="view === 'list'" class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('membership_id') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('name') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('category') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('position') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('status') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('debt') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="player in players.data" :key="player.id" class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                <td class="whitespace-nowrap px-4 py-3 text-sm font-mono text-slate-600 dark:text-slate-300">{{ player.membership_id }}</td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <Link :href="route('players.show', player.id)" class="text-sm font-medium text-slate-900 dark:text-slate-100 hover:text-primary-600">
                                        {{ player.fullname || `${player.lastname} ${player.firstname}` }}
                                    </Link>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ player.category?.name || '-' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ player.position?.abbreviation || '-' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <Badge v-if="player.archived" :label="t('archived')" color="slate" />
                                    <Badge v-else :label="t('active')" color="emerald" />
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-end text-sm font-semibold"
                                    :class="player.total_debt > 0 ? 'text-rose-700' : 'text-emerald-700'">
                                    {{ formatMoney(player.total_debt || 0) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-end">
                                    <div class="flex items-center justify-end gap-2">
                                        <Link :href="route('players.show', player.id)" class="text-sm text-primary-600 hover:text-primary-800">{{ t('details') }}</Link>
                                        <Link :href="route('players.edit', player.id)" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">{{ t('edit') }}</Link>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!players.data.length">
                                <td colspan="7" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="px-4">
                    <Pagination :links="players" />
                </div>
            </div>
        </div>

        <!-- Import modal -->
        <Teleport to="body">
            <div v-if="showImport" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" @click.self="showImport = false">
                <div class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ t('import_players') }}</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ t('import_players_hint') }}</p>
                    <form @submit.prevent="submitImport" class="mt-4 space-y-4">
                        <input
                            type="file"
                            accept=".xlsx,.xls,.csv"
                            @change="importForm.file = $event.target.files[0]"
                            required
                            class="w-full text-sm text-slate-600 dark:text-slate-300 file:me-4 file:rounded-lg file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary-700 hover:file:bg-primary-100"
                        />
                        <p v-if="importForm.errors.file" class="text-sm text-rose-600">{{ importForm.errors.file }}</p>
                        <div class="flex items-center justify-between gap-3 pt-2">
                            <a :href="route('players.import.template')" class="text-sm font-medium text-primary-600 hover:underline">{{ t('download_template') }}</a>
                            <div class="flex gap-2">
                                <button type="button" @click="showImport = false" class="rounded-lg border border-slate-300 dark:border-slate-700 px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">{{ t('cancel') }}</button>
                                <button type="submit" :disabled="importForm.processing || !importForm.file" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">{{ t('import') }}</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
