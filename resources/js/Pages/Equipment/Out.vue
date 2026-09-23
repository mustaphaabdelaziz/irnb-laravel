<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Badge from '@/Components/Badge.vue';
import Icon from '@/Components/Icon.vue';
import Pagination from '@/Components/Pagination.vue';
import RentalTypeBadge from '@/Components/RentalTypeBadge.vue';
import ReturnRentalModal from '@/Components/ReturnRentalModal.vue';
import SearchInput from '@/Components/SearchInput.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCan } from '@/Composables/useCan';
import { useListFilters } from '@/Composables/useListFilters';

const props = defineProps({
    rentals: { type: Object, required: true },
    counts: { type: Object, default: () => ({ rental: 0, assignment: 0 }) },
    filters: { type: Object, default: () => ({}) },
});
const { t } = useI18n();
const { can } = useCan();

const type = ref(props.filters?.type || 'rental');
const search = ref(props.filters?.search || '');
const { loading } = useListFilters('equipment.out', () => ({ type: type.value, search: search.value }), {
    only: ['rentals', 'counts', 'filters'],
});

const tabs = [
    { key: 'rental', label: 'equipment.rentals_tab' },
    { key: 'assignment', label: 'equipment.assignments_tab' },
];

const returning = ref(null);
</script>

<template>
    <Head :title="t('equipment_out')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('equipment.catalogs.index')" class="text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300"><Icon name="back" /></Link>
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('equipment_out') }}</h1>
            </div>
        </template>

        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex gap-1.5">
                    <button v-for="tab in tabs" :key="tab.key" type="button" @click="type = tab.key"
                        class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-sm font-semibold transition-colors"
                        :class="type === tab.key ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800'">
                        {{ t(tab.label) }}
                        <span class="rounded-full bg-black/10 px-1.5 text-xs dark:bg-white/10">{{ counts[tab.key] ?? 0 }}</span>
                    </button>
                </div>
                <div class="w-full sm:w-72"><SearchInput v-model="search" :loading="loading" :placeholder="t('search')" /></div>
            </div>

            <div :class="{ 'opacity-60': loading }" class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 transition-opacity dark:bg-slate-900 dark:ring-slate-800">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-950">
                            <tr>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('equipment.holder') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('equipment') }}</th>
                                <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('equipment.outstanding') }}</th>
                                <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('equipment.out_since') }}</th>
                                <th v-if="type === 'rental'" class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('due_date') }}</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="r in rentals.data" :key="r.id">
                                <td class="px-4 py-3 text-sm">
                                    <div class="flex items-center gap-2">
                                        <RentalTypeBadge :type="r.type" />
                                        <Link v-if="r.player_id" :href="route('players.show', r.player_id)" class="font-medium text-primary-600 hover:underline">{{ r.recipient_name }}</Link>
                                        <span v-else class="font-medium text-slate-800 dark:text-slate-200">{{ r.recipient_name || '—' }}</span>
                                    </div>
                                    <span v-if="r.membership_id" class="font-mono text-xs text-slate-400">{{ r.membership_id }}</span>
                                    <span v-else-if="r.external_phone" class="text-xs text-slate-400">{{ r.external_phone }}</span>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <Link v-if="r.catalog" :href="route('equipment.catalogs.show', r.catalog.id)" class="text-slate-800 hover:text-primary-600 dark:text-slate-200">{{ r.catalog.name }}</Link>
                                    <span v-if="r.item_label" class="block font-mono text-xs text-slate-400">{{ r.item_label }}</span>
                                </td>
                                <td class="px-4 py-3 text-end text-sm font-semibold text-slate-900 dark:text-slate-100">
                                    {{ r.outstanding_quantity }}<span v-if="r.quantity > 1" class="font-normal text-slate-400"> / {{ r.quantity }}</span>
                                </td>
                                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ r.checkout_date }}</td>
                                <td v-if="type === 'rental'" class="px-4 py-3 text-sm">
                                    <span class="text-slate-600 dark:text-slate-300">{{ r.due_date || '—' }}</span>
                                    <Badge v-if="r.is_overdue" :label="t('overdue')" color="rose" class="ms-2" />
                                </td>
                                <td class="px-4 py-3 text-end">
                                    <button v-if="can('equipment', 'edit')" type="button" @click="returning = r" class="text-sm font-medium text-emerald-600 hover:text-emerald-800">{{ t('return') }}</button>
                                </td>
                            </tr>
                            <tr v-if="!rentals.data?.length">
                                <td :colspan="type === 'rental' ? 6 : 5" class="px-4 py-10 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('equipment.none_out') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="px-4"><Pagination :links="rentals" /></div>
            </div>
        </div>

        <ReturnRentalModal :rental="returning" :title="returning?.catalog?.name || ''" @close="returning = null" />
    </AuthenticatedLayout>
</template>
