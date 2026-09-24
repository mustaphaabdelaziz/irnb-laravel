<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatStrip from '@/Components/Dashboard/StatStrip.vue';
import Pagination from '@/Components/Pagination.vue';
import Badge from '@/Components/Badge.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useFormatMoney } from '@/Composables/useFormatMoney';
import { ref, watch } from 'vue';
import { useListFilters } from '@/Composables/useListFilters';

const { t } = useI18n();
const { formatMoney } = useFormatMoney();

const props = defineProps({
    strip: { type: Array, default: () => [] },
    subscriptions: Object,
    branches: { type: Array, default: () => [] },
    branchStats: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    seasons: { type: Array, default: () => [] },
});

const branchFilter = ref(props.filters?.branch_id || '');
// Numbers, so the season option from the URL shows as selected.
const yearFilter = ref(props.filters?.year ? Number(props.filters.year) : '');
const kindFilter = ref(props.filters?.kind || '');
// A one-off charge has no season, so a season filter would hide them all.
watch(kindFilter, (kind) => { if (kind === 'exceptional') yearFilter.value = ''; });

const { loading: filtering } = useListFilters('subscriptions.index', () => ({
    branch_id: branchFilter.value,
    year: yearFilter.value,
    kind: kindFilter.value,
}), { only: ['subscriptions', 'filters'] });

const branchName = (row) => row.branch_id === null ? t('no_branch') : row.name;

const deleteId = ref(null);

function confirmDelete(id) {
    deleteId.value = id;
}

function destroy() {
    router.delete(route('subscriptions.destroy', deleteId.value), {
        onSuccess: () => { deleteId.value = null; },
    });
}
</script>

<template>
    <Head :title="t('subscriptions')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('subscriptions') }}</h1>
                <Link :href="route('subscriptions.create')" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700 transition-colors">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    {{ t('add_subscription') }}
                </Link>
            </div>
        </template>

        <StatStrip :tiles="strip || []" class="mb-4" />

        <div class="space-y-6">
            <!-- Per-branch financial breakdown -->
            <div v-if="branchStats.length" class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="border-b border-slate-100 dark:border-slate-800 px-5 py-4">
                    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('branch_financial_state') }}</h3>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ t('branches_overlap_note') }}</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('branch') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('owed') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('collected') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('outstanding_debt') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('players') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('paid_rate') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="row in branchStats" :key="row.branch_id ?? 'none'" class="hover:bg-slate-50 dark:hover:bg-slate-800">
                                <td class="px-4 py-3 text-sm font-medium" :class="row.branch_id === null ? 'text-slate-400 dark:text-slate-500 italic' : 'text-slate-900 dark:text-slate-100'">{{ branchName(row) }}</td>
                                <td class="px-4 py-3 text-end text-sm">{{ formatMoney(row.owed) }}</td>
                                <td class="px-4 py-3 text-end text-sm text-emerald-700 dark:text-emerald-400">{{ formatMoney(row.collected) }}</td>
                                <td class="px-4 py-3 text-end text-sm" :class="row.outstanding > 0 ? 'text-rose-700 dark:text-rose-400 font-semibold' : 'text-slate-500 dark:text-slate-400'">{{ formatMoney(row.outstanding) }}</td>
                                <td class="px-4 py-3 text-end text-sm text-slate-600 dark:text-slate-300">{{ row.players }}</td>
                                <td class="px-4 py-3 text-end text-sm text-slate-600 dark:text-slate-300">{{ row.paid_rate }}%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">{{ t('branch') }}</label>
                    <select v-model="branchFilter" class="mt-1 rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-900 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="">{{ t('all_branches') }}</option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.localized_name || b.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">{{ t('subscription_kind') }}</label>
                    <select v-model="kindFilter" class="mt-1 rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-900 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="">{{ t('all') }}</option>
                        <option value="annual">{{ t('subscription_kind_annual') }}</option>
                        <option value="exceptional">{{ t('subscription_kind_exceptional') }}</option>
                    </select>
                </div>
                <div v-if="kindFilter !== 'exceptional'">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">{{ t('season') }}</label>
                    <select v-model="yearFilter" class="mt-1 rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-900 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="">{{ t('all') }}</option>
                        <option v-for="s in seasons" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                </div>
            </div>

            <div :class="{ 'opacity-60': filtering }" :aria-busy="filtering" class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 transition-opacity dark:ring-slate-800">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('name') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('season') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('student_pricing') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('worker_pricing') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('categories') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('branches') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="sub in subscriptions.data" :key="sub.id" class="hover:bg-slate-50 dark:hover:bg-slate-800">
                                <td class="px-4 py-3 text-sm font-medium text-slate-900 dark:text-slate-100">{{ sub.name }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                                    <Badge v-if="sub.kind === 'exceptional'" :label="t('subscription_kind_exceptional')" color="amber" />
                                    <template v-else>{{ sub.year_label }}</template>
                                </td>
                                <td class="px-4 py-3 text-end text-sm">{{ formatMoney(sub.amount_student) }}</td>
                                <td class="px-4 py-3 text-end text-sm">{{ formatMoney(sub.amount_worker) }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        <Badge v-for="cat in sub.categories" :key="cat.id" :label="cat.localized_name || cat.name" color="primary" />
                                        <span v-if="!sub.categories?.length" class="text-xs text-slate-400 dark:text-slate-500">{{ t('all') }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        <Badge v-for="b in sub.branches" :key="b.id" :label="b.localized_name || b.name" color="emerald" />
                                        <span v-if="!sub.branches?.length" class="text-xs text-slate-400 dark:text-slate-500">{{ t('no_branch') }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-end">
                                    <div class="flex items-center justify-end gap-2">
                                        <Link :href="route('subscriptions.show', sub.id)" class="text-sm text-primary-600 hover:text-primary-800">{{ t('details') }}</Link>
                                        <Link :href="route('subscriptions.edit', sub.id)" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">{{ t('edit') }}</Link>
                                        <button @click="confirmDelete(sub.id)" class="text-sm text-rose-500 hover:text-rose-700">{{ t('delete') }}</button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!subscriptions.data?.length">
                                <td colspan="7" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="px-4">
                    <Pagination :links="subscriptions" />
                </div>
            </div>
        </div>

        <ConfirmModal
            :show="!!deleteId"
            :message="t('are_you_sure')"
            @confirm="destroy"
            @cancel="deleteId = null"
        />
    </AuthenticatedLayout>
</template>
